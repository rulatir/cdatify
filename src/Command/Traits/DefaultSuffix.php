<?php

namespace Rulatir\Cdatify\Command\Traits;

use Eloquent\Pathogen\FileSystem\Factory\PlatformFileSystemPathFactory;

trait DefaultSuffix
{
    protected function applyNameWithoutExtensionTransformer(string $inputPath, callable $transformer) : string
    {
        $pf = new PlatformFileSystemPathFactory();
        $path = $pf->create($inputPath);
        return $path
            ->replaceNameWithoutExtension(
                $transformer($path->nameWithoutExtension())
            )
            ->string();
    }

    protected function suffixer($suffix) : callable
    {
        return fn(string $str) => preg_replace("/(?:-$suffix)*$/","-$suffix",$str);
    }

    protected function unsuffixer($suffix) : callable
    {
        return fn(string $str) => preg_replace("/(?:-$suffix)*$/","",$str);
    }

    protected function buildDefaultOutputPath(string $inputPath) : string
    {
        return $this->applyNameWithoutExtensionTransformer($inputPath, $this->suffixer($this->getDefaultOutputSuffix()));
    }

    protected abstract function getDefaultOutputSuffix() : string;
}