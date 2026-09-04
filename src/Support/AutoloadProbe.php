<?php

declare(strict_types=1);

namespace RLT\Support;

/**
 * Exists only so a test can prove the plugin's own RLT\ autoloader (defined
 * in rakuten-link-tracker.php) resolves a class by path with Composer's
 * autoloader taken out of the stack. Nothing else in the plugin references
 * this class, so it is never loaded except when a test deliberately asks
 * for it.
 */
final class AutoloadProbe {

	public function ping(): string {
		return 'pong';
	}
}
