<?php

namespace App\Models;

use App\Services\Facebook\FacebookCatalog;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One Team's Meta Commerce Catalog credentials.
 *
 * Per Team rather than per installation: a merchant connects their own Meta
 * Business, and the platform never holds one catalogue everybody publishes into.
 * The unique index on `team_id` is what makes "the connection" a fair phrase.
 */
class FacebookConnection extends Model
{
    use HasFactory;
    use IsTenantModel;

    protected $fillable = [
        'access_token',
        'catalog_id',
        'business_id',
        'graph_version',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
    ];

    public static function forTeam(?int $teamId): ?self
    {
        return $teamId === null
            ? null
            : static::query()->where('team_id', $teamId)->first();
    }

    public function catalog(): FacebookCatalog
    {
        return new FacebookCatalog(
            (string) $this->access_token,
            (string) $this->catalog_id,
            (string) ($this->business_id ?? ''),
            (string) ($this->graph_version ?: 'v21.0'),
        );
    }
}
