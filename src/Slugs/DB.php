<?php

namespace Rulatir\Cdatify\Slugs;

use Cocur\Slugify\SlugifyInterface;

class DB
{
    public function __construct(
        protected array $input,
        protected SlugifyInterface $inputSlugifier,
        protected array $output,
        protected SlugifyInterface $outputSlugifier
    )
    {}


}