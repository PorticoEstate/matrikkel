<?php
/**
 * User: ingvar.aasen
 * Date: 26.09.2023
 */

namespace Iaasen\Matrikkel;

class Module {
	public function getConfig() {
		return include __DIR__ . '/../config/module.config.php';
	}
}