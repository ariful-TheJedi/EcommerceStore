<?php

namespace App\Catalog\Models;

use Database\Factories\FrontPageSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['content', 'version'])]
#[UseFactory(FrontPageSettingFactory::class)]
class FrontPageSetting extends Model
{
    /** @use HasFactory<FrontPageSettingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['content' => 'array', 'version' => 'integer'];
    }
}
