<?php

namespace App\Filament\Admin\Resources\ChatConversations;

use App\Filament\Admin\Resources\ChatConversations\Pages\ListChatConversations;
use App\Filament\Admin\Resources\ChatConversations\Pages\ViewChatConversation;
use App\Models\ChatConversation;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ChatConversationResource extends Resource
{
    protected static ?string $model = ChatConversation::class;

    /*
     * Tenant-scoped, by inheriting the panel's default. This used to declare
     * `$isScopedToTenant = false`, and the reason it gave was true — the table
     * had no `team_id` and the model no `team` relationship, so Filament would
     * have raised a LogicException rather than isolated anything.
     *
     * The reason was true and the outcome was still a leak: every staff user
     * saw every team's conversations, customer names, emails and message bodies
     * included. The fix is the missing key, not the opt-out — `ChatConversation`
     * now carries `team_id` and `store_id` via IsTenantModel and IsStoreScoped.
     */
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static string|\UnitEnum|null $navigationGroup = 'Customer Support';

    protected static ?string $navigationLabel = 'Chat Conversations';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Conversation Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('session_id')
                            ->label('Session ID')
                            ->disabled(),
                        Select::make('status')
                            ->options([
                                'queued' => 'Queued',
                                'active' => 'Active',
                                'closed' => 'Closed',
                            ])
                            ->required(),
                        Select::make('user_id')
                            ->label('Customer')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload(),
                        Select::make('agent_id')
                            ->label('Agent')
                            ->relationship('agent', 'name')
                            ->searchable()
                            ->preload(),
                        TextInput::make('customer_name')
                            ->label('Customer Name'),
                        TextInput::make('customer_email')
                            ->label('Customer Email')
                            ->email(),
                        DateTimePicker::make('started_at')
                            ->label('Started At'),
                        DateTimePicker::make('ended_at')
                            ->label('Ended At'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'queued' => 'warning',
                        'active' => 'success',
                        'closed' => 'gray',
                        default => 'secondary',
                    }),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->default('Guest'),
                TextColumn::make('customer_email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('agent.name')
                    ->label('Agent')
                    ->searchable()
                    ->default('Unassigned'),
                TextColumn::make('messages_count')
                    ->counts('messages')
                    ->label('Messages')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->label('Ended')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'queued' => 'Queued',
                        'active' => 'Active',
                        'closed' => 'Closed',
                    ]),
                SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->relationship('agent', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatConversations::route('/'),
            'view' => ViewChatConversation::route('/{record}'),
        ];
    }
}
