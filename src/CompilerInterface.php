<?php

namespace Datto\Cinnabari;

interface CompilerInterface
{
    public function compile($translatedRequest, $arguments);
}
