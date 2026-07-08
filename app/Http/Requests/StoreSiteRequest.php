<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:100', 'unique:sites,domain', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.[a-z0-9-]{1,63})+$/'],
            'repository' => ['required', 'string', 'max:255', 'regex:/^git@github\.com:[\w.-]+\/[\w.-]+\.git$/'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^(?!.*\.\.)[\w][\w\/.-]*$/'],
        ];
    }
}
