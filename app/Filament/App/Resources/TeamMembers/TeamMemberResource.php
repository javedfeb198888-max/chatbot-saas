<?php

namespace App\Filament\App\Resources\TeamMembers;

use App\Enums\Role;
use App\Filament\App\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Filament\App\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The User model is NOT client-scoped (login needs to find any user),
 * so this resource filters by client itself in getEloquentQuery().
 */
class TeamMemberResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'team';

    protected static ?string $modelLabel = 'team member';

    protected static ?string $navigationLabel = 'Team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        $clientId = auth()->user()?->client_id;

        return parent::getEloquentQuery()
            ->when(
                $clientId !== null,
                fn (Builder $query) => $query->where('client_id', $clientId),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(table: 'users', column: 'email'),
                Select::make('role')
                    ->options([
                        Role::Member->value => 'Member (can view chats, leads and bookings)',
                        Role::Owner->value => 'Owner (can also change settings and invite people)',
                    ])
                    ->default(Role::Member->value)
                    ->required()
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state) => ucfirst($state->value)),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->date(),
            ])
            ->recordActions([
                DeleteAction::make()->label('Remove'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamMembers::route('/'),
            'create' => CreateTeamMember::route('/create'),
        ];
    }
}
