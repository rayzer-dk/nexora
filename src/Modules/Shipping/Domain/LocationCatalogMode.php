<?php

declare(strict_types=1);

namespace Commerce\Modules\Shipping\Domain;

enum LocationCatalogMode: string
{
    /** No persistent carrier location catalogue; query provider on demand. */
    case LiveQuery = 'live_query';

    /** Short-lived response cache only. */
    case CachedQuery = 'cached_query';

    /** Persist/sync only settlements; load branches/lockers only after settlement selection. */
    case SyncedCitiesRemotePoints = 'synced_cities_remote_points';

    /** Persist a provider-approved complete location directory. */
    case SyncedDirectory = 'synced_directory';
}
