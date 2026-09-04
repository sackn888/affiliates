<?php

declare(strict_types=1);

namespace RLT\Frontend;

use RLT\Data\EventRepository;
use RLT\Data\LinkRepository;
use RLT\Settings;
use RLT\Support\CodeGenerator;
use RLT\Support\RequestContext;

/**
 * Resolves /{prefix}/{code}, records the click and sends the visitor on.
 *
 * The governing rule here: the redirect must happen even if tracking fails.
 * Losing a click record is an inconvenience; losing the affiliate click is
 * lost revenue.
 */
final class RedirectHandler {

	public const QUERY_VAR = 'rlt_code';

	/**
	 * Throttles the self-healing rewrite-rule check in maybeRepairRewriteRules().
	 */
	public const REPAIR_TRANSIENT = 'rlt_rewrite_repaired';

	private LinkRepository $links;
	private EventRepository $events;

	public function __construct( ?LinkRepository $links = null, ?EventRepository $events = null ) {
		$this->links  = $links ?? new LinkRepository();
		$this->events = $events ?? new EventRepository();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'addRewriteRule' ) );
		add_filter( 'query_vars', array( $this, 'addQueryVar' ) );
		add_action( 'template_redirect', array( $this, 'handle' ), 0 );
		add_action( 'admin_init', array( $this, 'maybeRepairRewriteRules' ) );
	}

	/**
	 * Register the rewrite rule for every prefix the site has ever used, not
	 * just the current one.
	 *
	 * The prefix is user-configurable and can be changed after links have
	 * already been published in post content. If only the current prefix
	 * were registered here, changing it would instantly 404 every short URL
	 * built with the old one. Settings::allPrefixes() returns the current
	 * prefix plus every past prefix, so old links keep resolving forever.
	 */
	public function addRewriteRule(): void {
		foreach ( Settings::allPrefixes() as $prefix ) {
			add_rewrite_rule(
				self::ruleKeyFor( $prefix ),
				'index.php?' . self::QUERY_VAR . '=$matches[1]',
				'top'
			);
		}
	}

	/**
	 * The `rewrite_rules` option key that a given prefix's rule is stored
	 * under. Shared between addRewriteRule() (which builds it) and
	 * maybeRepairRewriteRules() (which looks it up), so the two can never
	 * drift apart.
	 */
	private static function ruleKeyFor( string $prefix ): string {
		return '^' . $prefix . '/([a-z0-9]{4,16})/?$';
	}

	/**
	 * Self-heals a site whose cached rewrite rules are missing the current
	 * prefix's /go/ rule.
	 *
	 * Before this fix, Installer::activate() called flush_rewrite_rules()
	 * without ever having run addRewriteRule() first -- activation happens
	 * after `init` has already fired for that request, so the rule was never
	 * registered before the flush cached the rule set. Every site that
	 * activated the plugin while that bug existed is stuck with a
	 * /go/-less rule set, and a plugin *update* never re-runs the activation
	 * hook, so those sites would stay broken forever without an explicit
	 * repair. This runs on admin_init only -- never on a visitor request --
	 * and is throttled by a transient so a site that genuinely cannot
	 * persist rewrite rules (e.g. the web server ignores .htaccess) doesn't
	 * pay for flush_rewrite_rules(), which is expensive, on every single
	 * admin page load.
	 */
	public function maybeRepairRewriteRules(): void {
		// Rewrite rules don't apply at all with plain permalinks; /go/ can
		// never work either way, so there is nothing to repair.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		if ( false !== get_transient( self::REPAIR_TRANSIENT ) ) {
			return;
		}

		// Set the throttle before doing the (expensive) work, not after: a
		// site where the flush below doesn't actually fix anything must still
		// not retry it on every admin page load.
		set_transient( self::REPAIR_TRANSIENT, 1, HOUR_IN_SECONDS );

		$rules = get_option( 'rewrite_rules' );
		$key   = self::ruleKeyFor( Settings::prefix() );

		if ( is_array( $rules ) && array_key_exists( $key, $rules ) ) {
			return;
		}

		$this->addRewriteRule();
		flush_rewrite_rules();
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function addQueryVar( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public function resolve( string $code ): ?array {
		if ( ! CodeGenerator::isValid( $code ) ) {
			return null;
		}

		return $this->links->findByCode( $code );
	}

	/**
	 * @param array<string, mixed> $link
	 * @return bool True when a row was written.
	 */
	public function trackClick( array $link ): bool {
		do_action( 'rlt_before_track_click', $link );

		if ( Settings::get( 'exclude_logged_in' ) && is_user_logged_in() ) {
			return false;
		}

		$window = (int) Settings::get( 'click_dedup_seconds' );

		if ( $this->events->hasRecentClick( (int) $link['id'], RequestContext::visitorHash(), $window ) ) {
			return false;
		}

		return $this->events->recordClick( (int) $link['id'], (int) $link['post_id'] );
	}

	public function handle(): void {
		$code = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $code ) {
			return;
		}

		$this->sendNoCacheHeaders();

		$link = $this->resolve( $code );

		if ( null === $link ) {
			$this->handleUnknownCode();

			return;
		}

		$isHead = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );

		if ( ! $isHead ) {
			// Tracking is isolated: a failure here must never stop the redirect.
			try {
				$this->trackClick( $link );
			} catch ( \Throwable $e ) {
				error_log( '[rakuten-link-tracker] click tracking failed: ' . $e->getMessage() );
			}
		}

		// Belt-and-braces scheme check, even though LinkExtractor already
		// rejects non-http(s) URLs before a link is ever created: rows
		// inserted before that fix still exist, and target_url is editable
		// through the links admin screen (a later task), so a dangerous
		// value can still reach this point. wp_redirect() only sanitises
		// characters -- it does not restrict scheme or host the way
		// wp_safe_redirect() would -- so an unchecked value here would let a
		// public /{prefix}/{code} URL turn into an open redirect to
		// javascript:/data:/etc. This must never throw: a malformed
		// target_url falls through to the ordinary "unknown code" handling
		// instead of blocking the redirect guarantee for every other link.
		$scheme = strtolower( (string) parse_url( (string) $link['target_url'], PHP_URL_SCHEME ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			error_log( '[rakuten-link-tracker] refused to redirect code "' . $code . '": target_url has a disallowed scheme.' );

			$this->handleUnknownCode();

			return;
		}

		wp_redirect( $link['target_url'], 302 );
		exit;
	}

	private function handleUnknownCode(): void {
		if ( Settings::UNKNOWN_CODE_404 === Settings::get( 'unknown_code' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();

			return;
		}

		wp_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * Keep the redirect out of every cache layer.
	 *
	 * A cached /go/ response would serve the redirect without ever reaching PHP,
	 * and the click would go uncounted.
	 */
	private function sendNoCacheHeaders(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( headers_sent() ) {
			return;
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
}
