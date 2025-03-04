<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Exception;

// todo@v4 improve all exception messages

class ModelNotSyncMasterException extends Exception
{
    public function __construct(string $class)
    {
        parent::__construct("Model of $class class is not a SyncMaster model. Make sure you're using the central model to make changes to synced resources when you're in the central context.");
    }
}
