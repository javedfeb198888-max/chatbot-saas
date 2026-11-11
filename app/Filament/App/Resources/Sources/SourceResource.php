<?php

namespace App\Filament\App\Resources\Sources;

use App\Filament\App\Resources\Sources\Pages\CreateSource;
use App\Filament\App\Resources\Sources\Pages\ListSources;
use App\Jobs\TrainSource;
use App\Models\Source;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Website pages, sitemaps and pasted text the bot learns from.
 * Q&A pairs have their own screen, so they are left out here.
 */
class SourceResource extends Resource
{
    protected static ?string $model = Source::class;

    protected static ?string $slug = 'training';

    protected static string|UnitEnum|null $navigationGroup = 'Training';

    protected static ?string $navigationLabel = 'Website and text';

    protected static ?string $modelLabel = 'source';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', '!=', 'qa');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                ToggleButtons::make('type')
                    ->label('What do you want to add?')
                    ->options([
                        'url' => 'One web page',
                        'sitemap' => 'Whole website (sitemap)',
                        'text' => 'Paste text',
                    ])
                    ->default('url')
                    ->inline()
                    ->live()
                    ->required(),

                TextInput::make('url')
                    ->label(fn (Get $get) => $get('type') === 'sitemap' ? 'Sitemap address' : 'Page address')
                    ->placeholder(fn (Get $get) => $get('type') === 'sitemap' ? 'https://example.com/sitemap.xml' : 'https://example.com/about')
                    ->helperText(fn (Get $get) => $get('type') === 'sitemap'
                        ? 'Every page listed in the sitemap is added, up to '.config('chatbot.training.max_pages_per_bot').' pages.'
                        : null)
                    ->url()
                    ->maxLength(2000)
                    ->required()
                    ->visible(fn (Get $get) => in_array($get('type'), ['url', 'sitemap'], true)),

                TextInput::make('title')
                    ->label('Title')
                    ->placeholder('Parking and directions')
                    ->maxLength(255)
                    ->required()
                    ->visible(fn (Get $get) => $get('type') === 'text'),

                Textarea::make('content')
                    ->label('Text')
                    ->rows(10)
                    ->maxLength(50000)
                    ->required()
                    ->visible(fn (Get $get) => $get('type') === 'text'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('title')
                    ->label('Source')
                    ->placeholder('Waiting to be read')
                    ->description(fn (Source $record) => $record->url)
                    ->wrap()
                    ->searchable(['title', 'url']),
                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'url' => 'Page',
                        'sitemap' => 'Sitemap',
                        default => 'Text',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'ready' => 'success',
                        'failed' => 'danger',
                        'processing' => 'warning',
                        default => 'gray',
                    })
                    ->description(fn (Source $record) => $record->error)
                    ->wrap(),
                TextColumn::make('chunks_count')
                    ->counts('chunks')
                    ->label('Chunks'),
                TextColumn::make('last_synced_at')
                    ->label('Last trained')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('retrain')
                    ->label('Train again')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize('update')
                    ->action(function (Source $record) {
                        $record->update(['status' => 'pending', 'error' => null]);

                        TrainSource::dispatch($record->id, $record->client_id);

                        Notification::make()->title('Training started')->success()->send();
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSources::route('/'),
            'create' => CreateSource::route('/create'),
        ];
    }
}
