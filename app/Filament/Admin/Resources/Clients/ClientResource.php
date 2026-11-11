<?php

namespace App\Filament\Admin\Resources\Clients;

use App\Actions\SendInvite;
use App\Enums\Role;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Models\Client;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state, string $operation) {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->options(['active' => 'Active', 'suspended' => 'Suspended'])
                            ->default('active')
                            ->required()
                            ->native(false),
                        TextInput::make('monthly_message_limit')
                            ->label('Monthly message limit')
                            ->numeric()
                            ->minValue(0)
                            ->default(2000)
                            ->required(),
                    ]),

                Section::make('Owner login')
                    ->description('This person gets an email with a link to set their password.')
                    ->columns(2)
                    ->visibleOn('create')
                    ->schema([
                        TextInput::make('owner_name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('owner_email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'users', column: 'email'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'danger'),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users'),
                TextColumn::make('messages_this_month')
                    ->label('Messages this month')
                    ->state(fn (Client $record) => number_format($record->messagesThisMonth()).' of '.number_format($record->monthly_message_limit)),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('toggleStatus')
                    ->label(fn (Client $record) => $record->isActive() ? 'Suspend' : 'Activate')
                    ->color(fn (Client $record) => $record->isActive() ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(fn (Client $record) => $record->update([
                        'status' => $record->isActive() ? 'suspended' : 'active',
                    ])),
                Action::make('resendInvite')
                    ->label('Resend invite')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (Client $record, SendInvite $sendInvite) {
                        $owner = $record->users()->where('role', Role::Owner)->oldest()->first();

                        if ($owner === null) {
                            Notification::make()->title('This client has no owner.')->danger()->send();

                            return;
                        }

                        $sendInvite->handle($owner);

                        Notification::make()->title('Invite sent to '.$owner->email)->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClients::route('/'),
            'create' => CreateClient::route('/create'),
            'edit' => EditClient::route('/{record}/edit'),
        ];
    }
}
