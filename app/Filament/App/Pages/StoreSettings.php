<?php

namespace App\Filament\App\Pages;

use App\Filament\Schemas\StoreSettingsForm;
use App\Models\Store;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * @property-read Schema $form
 */
class StoreSettings extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Store settings';

    protected static ?string $title = 'Store settings';

    protected static ?string $slug = 'store-settings';

    protected static ?int $navigationSort = 12;

    /** Which of the team's stores is being edited, when it has several. */
    #[Url]
    public ?int $store = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && static::stores()->contains(fn (Store $store) => Gate::forUser($user)->allows('manageSettings', $store));
    }

    /** @return Collection<int, Store> */
    public static function stores(): Collection
    {
        return Store::query()->where('team_id', Filament::getTenant()?->getKey())->orderBy('name')->get();
    }

    public function getRecord(): Store
    {
        $stores = static::stores();
        $record = $stores->firstWhere('id', $this->store) ?? $stores->first();

        abort_if($record === null, 404);
        Gate::authorize('manageSettings', $record);

        return $record;
    }

    public function mount(): void
    {
        $this->form->fill(StoreSettingsForm::fill($this->getRecord()));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(StoreSettingsForm::sections())
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $record = $this->getRecord();
        $record->update(StoreSettingsForm::dehydrate($this->form->getState()));

        Notification::make()->success()->title('Store settings saved')->send();
    }

    protected function getHeaderActions(): array
    {
        $stores = static::stores();

        if ($stores->count() < 2) {
            return [];
        }

        return [
            ActionGroup::make($stores->map(fn (Store $store) => Action::make('store-'.$store->id)
                ->label($store->name)
                ->url(static::getUrl(['store' => $store->id])))->all())
                ->label('Editing: '.$this->getRecord()->name)
                ->button(),
        ];
    }
}
