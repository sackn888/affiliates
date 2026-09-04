<?php

namespace RLT\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RLT\Support\CodeGenerator;

final class CodeGeneratorTest extends TestCase {

	public function test_generates_code_of_expected_length(): void {
		$this->assertSame( 6, strlen( CodeGenerator::generate() ) );
	}

	public function test_alphabet_excludes_ambiguous_characters(): void {
		foreach ( array( '0', 'o', '1', 'l', 'i' ) as $ambiguous ) {
			$this->assertStringNotContainsString(
				$ambiguous,
				CodeGenerator::ALPHABET,
				"Alphabet must not contain the ambiguous character '{$ambiguous}'."
			);
		}
	}

	public function test_generated_code_uses_only_alphabet_characters(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			$code = CodeGenerator::generate();
			$this->assertSame(
				'',
				trim( $code, CodeGenerator::ALPHABET ),
				"Code '{$code}' contains characters outside the alphabet."
			);
		}
	}

	public function test_generates_different_codes(): void {
		$codes = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$codes[] = CodeGenerator::generate();
		}

		// 31^6 の空間から100件引いて全部同じになることは実質ありえない。
		$this->assertGreaterThan( 90, count( array_unique( $codes ) ) );
	}

	public function test_is_valid_accepts_generated_codes(): void {
		$this->assertTrue( CodeGenerator::isValid( CodeGenerator::generate() ) );
	}

	/**
	 * @dataProvider invalidCodes
	 */
	public function test_is_valid_rejects_bad_input( string $code ): void {
		$this->assertFalse( CodeGenerator::isValid( $code ) );
	}

	public static function invalidCodes(): array {
		return array(
			'empty'             => array( '' ),
			'too short'         => array( 'abc' ),
			'too long'          => array( 'abcdefghijklmnopq' ),
			'has slash'         => array( 'abc/de' ),
			'has uppercase'     => array( 'ABCDEF' ),
			'has dot'           => array( 'abcd.f' ),
			'trailing newline'  => array( "abcdef\n" ),
			'leading newline'   => array( "\nabcdef" ),
			'trailing space'    => array( 'abcdef ' ),
			'embedded newline'  => array( "abc\ndef" ),
			'null byte'         => array( "abcdef\0" ),
			'too long by one'   => array( 'abcdefghjkmnpqrs2' ),
		);
	}

	/**
	 * @dataProvider boundaryCodes
	 */
	public function test_is_valid_accepts_the_length_boundaries( string $code ): void {
		$this->assertTrue( CodeGenerator::isValid( $code ) );
	}

	public static function boundaryCodes(): array {
		return array(
			'minimum length' => array( 'abcd' ),
			'maximum length' => array( 'abcdefghjkmnpqrs' ),
		);
	}
}
