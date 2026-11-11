<?php

namespace App\Filament\App\Resources\QaPairs;

use App\Filament\App\Resources\QaPairs\Pages\CreateQaPair;
use App\Filament\App\Resources\QaPairs\Pages\EditQaPair;
use App\Filament\App\Resources\QaPairs\Pages\ListQaPairs;
use App\Models\QaPair;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class QaPairResource extends Resource
{
    protected static ?string $model = QaPair::class;

    protected static ?string $slug = 'questions';

    protected static string|UnitEnum|null $navigationGroup = 'Training';

    protected static ?string $navigationLabel = 'Questions and answers';

    protected static ?string $modelLabel = 'question';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Textarea::make('question')
                    ->helperText('Write it the way a visitor would ask it.')
                    ->rows(2)
                    ->maxLength(500)
                    ->required(),
                Textarea::make('answer')
                    ->helperText('The bot uses this answer whenever a visitor asks something similar.')
                    ->rows(6)
                    ->maxLength(3000)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('question')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('answer')
                    ->limit(90)
                    ->wrap(),
                TextColumn::make('source.status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => ucfirst((string) $state))
                    ->color(fn (?string $state) => match ($state) {
                        'ready' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQaPairs::route('/'),
            'create' => CreateQaPair::route('/create'),
            'edit' => EditQaPair::route('/{record}/edit'),
        ];
    }
}
