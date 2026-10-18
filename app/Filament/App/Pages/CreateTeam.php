<?php

namespace App\Filament\App\Pages;

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/** A merchant signing up: their team, and on saas or marketplace installations its store. */
class CreateTeam extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Open your store';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Store name')
                ->required()
                ->maxLength(255),
        ]);
    }

    protected function handleRegistration(array $data): Model
    {
        return app(CreateTeamAction::class)->create(auth()->user(), $data);
    }
}
