<?php

namespace Rulatir\Cdatify\Command;

use Ahc\Cli\Input\Command as AhcCommand;
use Rulatir\Cdatify\Command\Traits\DefaultSuffix;

abstract class ReversibleCommand extends Command
{
    use DefaultSuffix;

    protected bool $remove = false;
    public function __construct(AhcCommand $args)
    {
        $values = $args->values(true);
        $this->remove = $values['remove'] ?? false;
        parent::__construct($args);
    }

    protected function buildDefaultOutputPath(string $inputPath): string
    {
        $suffix = $this->getDefaultOutputSuffix();
        $result = $this->applyNameWithoutExtensionTransformer(
            $inputPath,
            $this->remove ? $this->unsuffixer($suffix) : $this->suffixer($suffix)
        );
        return $result;
    }
}