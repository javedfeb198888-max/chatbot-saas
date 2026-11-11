<?php

namespace App\Filament\App\Resources\Conversations;

use App\Filament\App\Resources\Conversations\Pages\ListConversations;
use App\Filament\App\Resources\Conversations\Pages\ViewConversation;
use App\Models\Conversation;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static ?string $slug = 'conversations';

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime('j M Y, H:i')
                    ->sortable(),
                TextColumn::make('lead.name')
                    ->label('Visitor')
                    ->placeholder('Anonymous')
                    ->description(fn (Conversation $record) => $record->lead?->email),
                TextColumn::make('transcript_count')
                    ->counts('transcript')
                    ->label('Messages'),
                TextColumn::make('page_url')
                    ->label('Page')
                    ->limit(45)
                    ->placeholder('Unknown'),
                TextColumn::make('last_message_at')
                    ->label('Last message')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Visitor')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('lead.name')->label('Name')->placeholder('Not given'),
                        TextEntry::make('lead.email')->label('Email')->placeholder('Not given')->copyable(),
                        TextEntry::make('lead.phone')->label('Phone')->placeholder('Not given')->copyable(),
                        TextEntry::make('created_at')->label('Started')->dateTime('j M Y, H:i'),
                        TextEntry::make('visitor_timezone')->label('Time zone')->placeholder('Unknown'),
                        TextEntry::make('page_url')->label('Page')->placeholder('Unknown'),
                    ]),

                Section::make('Messages')
                    ->schema([
                        RepeatableEntry::make('transcript')
                            ->hiddenLabel()
                            ->columns(6)
                            ->schema([
                                TextEntry::make('role')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->formatStateUsing(fn (string $state) => $state === 'user' ? 'Visitor' : 'Bot')
                                    ->color(fn (string $state) => $state === 'user' ? 'gray' : 'primary'),
                                TextEntry::make('content')
                                    ->hiddenLabel()
                                    ->columnSpan(4),
                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->dateTime('H:i')
                                    ->color('gray'),
                            ]),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }
}
