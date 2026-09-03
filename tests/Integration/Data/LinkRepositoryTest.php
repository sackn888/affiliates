<?php

namespace RLT\Tests\Integration\Data;

use RLT\Data\LinkRepository;
use RLT\Installer;
use RLT\Settings;
use WP_UnitTestCase;

final class LinkRepositoryTest extends WP_UnitTestCase {

	private LinkRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new LinkRepository();
	}

	public function test_find_or_create_inserts_a_new_row(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );

		$this->assertIsArray( $link );
		$this->assertSame( 10, $link['post_id'] );
		$this->assertSame( 'ホテルA', $link['label'] );
		$this->assertSame( 1, $link['status'] );
		$this->assertSame( 6, strlen( $link['code'] ) );
	}

	public function test_find_or_create_is_idempotent(): void {
		$first  = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );
		$second = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( $first['code'], $second['code'] );
	}

	public function test_same_url_in_a_different_post_gets_its_own_code(): void {
		$a = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );
		$b = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 11, 'ホテルA' );

		$this->assertNotSame( $a['code'], $b['code'] );
	}

	public function test_reactivates_and_relabels_an_archived_row(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, '古いラベル' );
		$this->repo->update( $link['id'], array( 'status' => 0 ) );

		$again = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, '新しいラベル' );

		$this->assertSame( $link['id'], $again['id'] );
		$this->assertSame( 1, $again['status'] );
		$this->assertSame( '新しいラベル', $again['label'] );
	}

	public function test_find_by_code_returns_typed_row(): void {
		$created = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'ホテルA' );

		$found = $this->repo->findByCode( $created['code'] );

		$this->assertIsInt( $found['id'] );
		$this->assertIsInt( $found['post_id'] );
		$this->assertIsInt( $found['status'] );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $found['target_url'] );
	}

	public function test_find_by_code_returns_null_when_missing(): void {
		$this->assertNull( $this->repo->findByCode( 'zzzzzz' ) );
	}

	public function test_find_by_post_filters_archived_by_default(): void {
		$a = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$b = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );
		$this->repo->update( $b['id'], array( 'status' => 0 ) );

		$active = $this->repo->findByPost( 10 );
		$all    = $this->repo->findByPost( 10, false );

		$this->assertCount( 1, $active );
		$this->assertSame( $a['id'], $active[0]['id'] );
		$this->assertCount( 2, $all );
	}

	public function test_count_active_for_post(): void {
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );

		$this->assertSame( 2, $this->repo->countActiveForPost( 10 ) );
		$this->assertSame( 0, $this->repo->countActiveForPost( 99 ) );
	}

	public function test_archive_others_archives_only_the_ones_not_kept(): void {
		$keep = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$drop = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );
		$other = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/c', 11, 'C' );

		$archived = $this->repo->archiveOthers( 10, array( $keep['id'] ) );

		$this->assertSame( 1, $archived );
		$this->assertSame( 1, $this->repo->findById( $keep['id'] )['status'] );
		$this->assertSame( 0, $this->repo->findById( $drop['id'] )['status'] );
		// 別の記事のリンクには手を出さない。
		$this->assertSame( 1, $this->repo->findById( $other['id'] )['status'] );
	}

	public function test_archive_others_with_empty_keep_list_archives_all_for_the_post(): void {
		$a = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$this->assertSame( 1, $this->repo->archiveOthers( 10, array() ) );
		$this->assertSame( 0, $this->repo->findById( $a['id'] )['status'] );
	}

	public function test_update_changes_target_url_and_label(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$this->assertTrue(
			$this->repo->update(
				$link['id'],
				array(
					'target_url' => 'https://hb.afl.rakuten.co.jp/hgc/new',
					'label'      => '新ラベル',
				)
			)
		);

		$updated = $this->repo->findById( $link['id'] );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/new', $updated['target_url'] );
		$this->assertSame( '新ラベル', $updated['label'] );
		// url_hash も追随していないと findOrCreate が重複行を作ってしまう。
		$this->assertSame( sha1( 'https://hb.afl.rakuten.co.jp/hgc/new' ), $updated['url_hash'] );
	}

	public function test_update_ignores_unknown_fields(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$this->repo->update( $link['id'], array( 'id' => 999, 'nonsense' => 'x' ) );

		$this->assertNotNull( $this->repo->findById( $link['id'] ) );
	}

	public function test_restore_map_maps_short_urls_back_to_originals(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		$map = $this->repo->restoreMap();

		$this->assertArrayHasKey( Settings::shortUrl( $link['code'] ), $map );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $map[ Settings::shortUrl( $link['code'] ) ] );
	}

	public function test_restore_map_includes_archived_links(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$this->repo->update( $link['id'], array( 'status' => 0 ) );

		// アーカイブ済みでも本文には短縮URLが残っているため、復元対象に含める必要がある。
		$this->assertArrayHasKey( Settings::shortUrl( $link['code'] ), $this->repo->restoreMap() );
	}

	public function test_restore_map_contains_both_old_and_new_prefix_keys_after_a_prefix_change(): void {
		$link = $this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );

		// A link's short URL may have been created and published under the
		// old prefix; the site owner then changes the prefix in settings.
		// restoreMap() must still be able to turn that old-prefix short URL
		// back into the original affiliate URL, or the "restore" feature
		// silently breaks for every link created before the change.
		Settings::update( array( 'prefix' => 'out' ) );

		$map = $this->repo->restoreMap();

		$oldKey = home_url( '/go/' . $link['code'] );
		$newKey = Settings::shortUrl( $link['code'] );

		$this->assertArrayHasKey( $oldKey, $map );
		$this->assertArrayHasKey( $newKey, $map );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $map[ $oldKey ] );
		$this->assertSame( 'https://hb.afl.rakuten.co.jp/hgc/a', $map[ $newKey ] );
	}

	public function test_post_ids_with_links(): void {
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/a', 10, 'A' );
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/b', 10, 'B' );
		$this->repo->findOrCreate( 'https://hb.afl.rakuten.co.jp/hgc/c', 11, 'C' );

		$ids = $this->repo->postIdsWithLinks();

		sort( $ids );
		$this->assertSame( array( 10, 11 ), $ids );
	}
}
