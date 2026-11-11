<?php

namespace App\Chat\Tools;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SaveContactDetails implements Tool
{
    public function __construct(private Bot $bot, private Conversation $conversation)
    {
    }

    public function name(): string
    {
        return 'save_contact_details';
    }

    public function description(): string
    {
        return 'Save the visitor\'s contact details so the team can follow up. Call it once the visitor has given a name and an email address or phone number.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The visitor\'s name.')->required(),
            'email' => $schema->string()->description('The visitor\'s email address, if given.'),
            'phone' => $schema->string()->description('The visitor\'s phone number, if given.'),
            'notes' => $schema->string()->description('One short line on what the visitor wants.'),
        ];
    }

    public function handle(Request $request): string
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:50', 'required_without:email'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return 'Not saved: '.$validator->errors()->first().' Ask the visitor to correct it.';
        }

        static::store($this->bot, $this->conversation, $validator->validated());

        return 'Contact details saved.';
    }

    /**
     * One lead per conversation; later details fill in or replace earlier ones.
     *
     * @param  array<string, ?string>  $details
     */
    public static function store(Bot $bot, Conversation $conversation, array $details): Lead
    {
        $lead = Lead::firstOrNew(['conversation_id' => $conversation->id]);

        $lead->bot_id = $bot->id;
        $lead->fill(array_filter($details, fn ($value) => filled($value)));
        $lead->save();

        return $lead;
    }
}
