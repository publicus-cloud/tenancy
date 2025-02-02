<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use Illuminate\Foundation\Vite as BaseVite;

class Vite extends BaseVite
{
    /**
     * Generate an asset path for the application.
     *
     * @param  string  $path
     * @param  bool|null  $secure
     * @return string
     */
    protected function assetPath($path, $secure = null)
    {
        return global_asset($path);
    }
}
