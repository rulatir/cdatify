<?php

namespace Rulatir\Cdatify\Command;

use Garden\Cli\Args;
use Rulatir\Cdatify\Command\Traits\DefaultSuffix;

abstract class ReversibleCommand extends Command
{
    use DefaultSuffix;

    protected bool $remove = false;
    public function __construct(Args $args)
    {
        $this->remove = $args->getOpt('remove', false);
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