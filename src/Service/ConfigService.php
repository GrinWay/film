<?php

namespace App\Service;

use GrinWay\Service\Service\ConfigService as GrinWayConfigService;
use Symfony\Component\OptionsResolver\{
    Options,
    OptionsResolver
};

class ConfigService extends GrinWayConfigService
{
	protected function configureConfigOptions(
		string $uniqPackId,
		OptionsResolver $resolver,
		array $inputData,
	): void {
	}
}
