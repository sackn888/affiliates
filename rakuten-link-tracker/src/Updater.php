<?php

declare(strict_types=1);

namespace RLT;

/**
 * Self-updater that checks a public GitHub repository directly -- no
 * external update-checker library, no build step, no tokens.
 *
 * The owner runs this plugin on shared hosting with no reliable SSH, so
 * "update" has to mean nothing more than `git push` to the default branch.
 * Each site periodically reads the plugin header straight off the raw file
 * on that branch, and if WordPress decides to install the update, it
 * downloads the branch's zipball from GitHub -- the same thing `git archive`
 * would produce for that branch, no releases or tags required.
 */
final class Updater {

	private const TRANSIENT = 'rlt_updater_remote_check';
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private string $repo;
	private string $branch;
	private string $path;

	/**
	 * @param string $repo   GitHub "owner/name" repository.
	 * @param string $branch Default branch the updater tracks.
	 * @param string $path   Path, relative to the repository root, of the
	 *                       directory the plugin actually lives in. Empty
	 *                       when the plugin sits at the repository root.
	 */
	public function __construct( string $repo, string $branch = 'master', string $path = '' ) {
		$this->repo   = $repo;
		$this->branch = $branch;
		$this->path   = trim( $path, '/' );
	}

	/**
	 * The repo constant ships as a literal "OWNER/..." placeholder until the
	 * account name is filled in. Every entry point must no-op cleanly until
	 * then -- no requests, no notices, nothing that could misbehave against
	 * a repository that doesn't exist.
	 */
	private function isConfigured(): bool {
		return '' !== $this->repo && false === strpos( $this->repo, 'OWNER' );
	}

	/**
	 * The path, relative to the repository root, to the plugin's own file --
	 * either "{path}/rakuten-link-tracker.php" or, when the plugin sits at
	 * the repository root, just "rakuten-link-tracker.php".
	 */
	private function pluginFileRepoPath(): string {
		return '' === $this->path
			? 'rakuten-link-tracker.php'
			: $this->path . '/rakuten-link-tracker.php';
	}

	public function register(): void {
		if ( ! $this->isConfigured() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'checkForUpdate' ) );
		add_filter( 'plugins_api', array( $this, 'pluginInfo' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fixSourceDir' ), 10, 4 );
		add_action( 'admin_notices', array( $this, 'adminNotice' ) );
	}

	/**
	 * Reads the plugin header straight off the raw file on the branch and
	 * pulls the Version out of it -- downloading the whole archive just to
	 * read one line would be wasteful and slower on every check.
	 *
	 * The result (success or failure) is cached in a transient for 12 hours,
	 * so an unreachable or slow GitHub never costs more than one delayed
	 * admin page load per half-day.
	 */
	public function remoteVersion(): ?string {
		if ( ! $this->isConfigured() ) {
			return null;
		}

		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && array_key_exists( 'version', $cached ) ) {
			return $cached['version'];
		}

		$url = sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s',
			$this->repo,
			$this->branch,
			$this->pluginFileRepoPath()
		);

		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TRANSIENT, array( 'version' => null ), self::CACHE_TTL );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $body, $matches ) ) {
			set_transient( self::TRANSIENT, array( 'version' => null ), self::CACHE_TTL );
			return null;
		}

		$version = trim( $matches[1] );

		set_transient( self::TRANSIENT, array( 'version' => $version ), self::CACHE_TTL );

		return $version;
	}

	/**
	 * Hooked to pre_set_site_transient_update_plugins.
	 */
	public function checkForUpdate( $transient ) {
		if ( ! $this->isConfigured() ) {
			return $transient;
		}

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$remote = $this->remoteVersion();

		if ( null === $remote || version_compare( $remote, Plugin::VERSION, '<=' ) ) {
			return $transient;
		}

		$basename = plugin_basename( RLT_PLUGIN_FILE );

		$item              = new \stdClass();
		$item->slug        = dirname( $basename );
		$item->plugin      = $basename;
		$item->new_version = $remote;
		$item->url         = sprintf( 'https://github.com/%s', $this->repo );
		$item->package     = sprintf( 'https://github.com/%s/archive/refs/heads/%s.zip', $this->repo, $this->branch );
		// "tested" (the WordPress version this was verified against) can't be
		// determined honestly from a raw file fetch, so it's left unset
		// rather than invented.

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $basename ] = $item;

		return $transient;
	}

	/**
	 * Hooked to plugins_api, for the "View details" popup WordPress shows
	 * from the update row.
	 */
	public function pluginInfo( $result, string $action, $args ) {
		if ( ! $this->isConfigured() || 'plugin_information' !== $action ) {
			return $result;
		}

		$basename = plugin_basename( RLT_PLUGIN_FILE );
		$slug     = dirname( $basename );

		$requestedSlug = is_object( $args ) && isset( $args->slug ) ? $args->slug : null;

		if ( $slug !== $requestedSlug ) {
			return $result;
		}

		$remote = $this->remoteVersion();

		if ( null === $remote ) {
			return $result;
		}

		$info                 = new \stdClass();
		$info->name           = 'Rakuten Link Tracker';
		$info->slug           = $slug;
		$info->version        = $remote;
		$info->author         = 'yusaku';
		$info->homepage       = sprintf( 'https://github.com/%s', $this->repo );
		$info->download_link  = sprintf( 'https://github.com/%s/archive/refs/heads/%s.zip', $this->repo, $this->branch );
		$info->sections       = array(
			'description' => sprintf(
				/* translators: %s: GitHub "owner/name" repository. */
				__( '%s の既定ブランチから直接インストールされます。', 'rakuten-link-tracker' ),
				esc_html( $this->repo )
			),
		);

		return $info;
	}

	/**
	 * Hooked to upgrader_source_selection.
	 *
	 * GitHub's branch zipball extracts to "{name}-{branch}/", not the
	 * plugin's real directory name -- and when the plugin is published from
	 * an in-repo path (RLT_GITHUB_PATH), that extracted directory is the
	 * whole repository, with the plugin one or more levels deeper inside it
	 * (e.g. "affiliates-main/rakuten-link-tracker/"). Left alone, WordPress
	 * would install either a second, differently-named plugin directory, or
	 * the entire repository in place of the plugin. This descends into the
	 * configured in-repo path first (if any), then renames the resulting
	 * directory to match the plugin's real directory name before WordPress
	 * moves it into place.
	 */
	public function fixSourceDir( $source, $remoteSource, $upgrader, $args ) {
		if ( ! $this->isConfigured() || ! is_string( $source ) ) {
			return $source;
		}

		$basename = plugin_basename( RLT_PLUGIN_FILE );

		// Only ever act on this plugin's own upgrade -- never another
		// plugin's, and never a theme or core upgrade passing through the
		// same filter.
		$hookPlugin = is_array( $args ) && isset( $args['plugin'] ) ? $args['plugin'] : null;

		if ( $basename !== $hookPlugin ) {
			return $source;
		}

		$pluginDirName = dirname( $basename );
		$sourceTrimmed = untrailingslashit( $source );

		if ( '' !== $this->path ) {
			$inner = untrailingslashit( $sourceTrimmed . '/' . $this->path );

			if ( ! is_dir( $inner ) ) {
				// The archive doesn't contain the plugin directory where it
				// was expected. Installing $source as-is here would put the
				// whole repository in place of the plugin on every site that
				// updates -- refuse instead.
				return new \WP_Error(
					'rlt_updater_missing_plugin_dir',
					sprintf(
						/* translators: %s: expected in-repo path to the plugin. */
						__( '更新用アーカイブの中に想定したプラグインディレクトリ（%s）が見つかりませんでした。', 'rakuten-link-tracker' ),
						$this->path
					)
				);
			}

			$sourceTrimmed = $inner;
		}

		if ( basename( $sourceTrimmed ) === $pluginDirName ) {
			return trailingslashit( $sourceTrimmed );
		}

		$desired = trailingslashit( dirname( $sourceTrimmed ) ) . $pluginDirName;

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->move( $sourceTrimmed, $desired ) ) {
			return trailingslashit( $desired );
		}

		if ( @rename( $sourceTrimmed, $desired ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return trailingslashit( $desired );
		}

		return trailingslashit( $sourceTrimmed );
	}

	/**
	 * Hooked to admin_notices. A silent failure here would leave a site
	 * stuck on an old version with nothing to notice, so the last cached
	 * check result (which remoteVersion() records even on failure) is
	 * surfaced to anyone who could act on it.
	 */
	public function adminNotice(): void {
		if ( ! $this->isConfigured() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$cached = get_transient( self::TRANSIENT );

		if ( ! is_array( $cached ) || array_key_exists( 'version', $cached ) === false || null !== $cached['version'] ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Rakuten Link Tracker: 更新の確認に失敗しました。GitHubへの接続を確認してください。', 'rakuten-link-tracker' )
		);
	}
}
