<?php
/**
 * Tests für die Bewertungen der Systemprüfung.
 *
 * @package Akuma\WebpUmwandler
 */

use Akuma\WebpUmwandler\System_Check;
use PHPUnit\Framework\TestCase;

/**
 * Grenzwerte für Arbeitsspeicher und Laufzeit.
 */
class System_Check_Test extends TestCase {

	/**
	 * Ab 256 MB oder unbegrenzt in Ordnung, darunter Warnung.
	 */
	public function test_memory_status() {
		$this->assertSame( System_Check::OK, System_Check::memory_status( -1 ) );
		$this->assertSame( System_Check::OK, System_Check::memory_status( 256 * 1024 * 1024 ) );
		$this->assertSame( System_Check::OK, System_Check::memory_status( 512 * 1024 * 1024 ) );
		$this->assertSame( System_Check::WARN, System_Check::memory_status( 128 * 1024 * 1024 ) );
	}

	/**
	 * Ab 30 Sekunden oder unbegrenzt (0) in Ordnung, darunter Warnung.
	 */
	public function test_execution_time_status() {
		$this->assertSame( System_Check::OK, System_Check::execution_time_status( 0 ) );
		$this->assertSame( System_Check::OK, System_Check::execution_time_status( 30 ) );
		$this->assertSame( System_Check::OK, System_Check::execution_time_status( 300 ) );
		$this->assertSame( System_Check::WARN, System_Check::execution_time_status( 20 ) );
	}
}
