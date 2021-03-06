<?php

declare(strict_types=1);

namespace KarlosAgudo\Fixtro\Tests\CodeQualityTool\Commands\Mock;

use KarlosAgudo\Fixtro\CodeQualityTool\Commands\GeneralCommand;

class CommandWithoutAnalyzers extends GeneralCommand
{
	protected $analyzers = 'should be an array but its a string, test fail';

	/**
	 * Configure command.
	 */
	protected function configure()
	{
		$this->setName('bad-test');
	}
}
