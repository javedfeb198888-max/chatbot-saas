<?php

namespace App\Filament\App\Resources\Bots;

use App\Filament\App\Resources\Bots\Pages\EditBot;
use App\Filament\App\Resources\Bots\Pages\ListBots;
use App\Models\Bot;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Bot model is client-scoped, so this resource only ever lists the
 * signed-in client's own bots.
 */
class BotResource extends Resource
{
    protected static ?string $model = Bot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Bot';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bot')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('goal')
                            ->options([
                                'booking' => 'Book appointments',
                                'support' => 'Answer questions',
                                'lead_capture' => 'Collect leads',
                            ])
                            ->required()
                            ->native(false),
                        Toggle::make('is_active')
                            ->label('Bot is live')
                            ->helperText('When off, the widget does not answer visitors.'),
                    ]),

                Section::make('Messages')
                    ->schema([
                        Textarea::make('greeting')
                            ->label('First message')
                            ->rows(2)
                            ->maxLength(500),
                        Textarea::make('system_prompt')
                            ->label('Instructions for the bot')
                            ->helperText('Tone, what to focus on, what to avoid. The bot answers only from your training content.')
                            ->rows(6)
                            ->maxLength(5000),
                    ]),

                Section::make('Booking calendar')
                    ->description('Connect your Google or Outlook calendar inside Cal.com, then enter your Cal.com details here. Leave empty to turn booking off.')
                    ->columns(3)
                    // Saved to calendar_connections only when a username is entered.
                    ->relationship('calendarConnection', condition: fn (?array $state) => filled($state['cal_username'] ?? null))
                    ->schema([
                        TextInput::make('cal_username')
                            ->label('Cal.com username')
                            ->placeholder('your-name')
                            ->helperText('The part after cal.com/ in your booking link.')
                            ->maxLength(255),
                        TextInput::make('event_type_slug')
                            ->label('Event type')
                            ->placeholder('30min')
                            ->helperText('The last part of the booking link.')
                            ->requiredWith('cal_username')
                            ->maxLength(255),
                        Select::make('timezone')
                            ->label('Your time zone')
                            ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->default('UTC')
                            ->searchable()
                            ->requiredWith('cal_username'),
                    ]),

                Section::make('Widget')
                    ->columns(2)
                    ->schema([
                        ColorPicker::make('primary_color')
                            ->label('Colour')
                            ->required(),
                        Select::make('position')
                            ->options(['right' => 'Bottom right', 'left' => 'Bottom left'])
                            ->required()
                            ->native(false),
                        TagsInput::make('allowed_domains')
                            ->label('Websites where the widget may run')
                            ->placeholder('example.com')
                            ->helperText('Type a domain and press Enter. Leave empty to allow any website; once you add one, every other site is blocked.')
                            ->columnSpanFull(),
                        Textarea::make('embed_code')
                            ->label('Widget code')
                            ->helperText('Paste this before </body> on your website.')
                            ->rows(2)
                            ->disabled()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('goal')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Live')
                    ->boolean(),
                TextColumn::make('public_key')
                    ->label('Public key')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->since()
                    ->label('Updated'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBots::route('/'),
            'edit' => EditBot::route('/{record}/edit'),
        ];
    }
}
