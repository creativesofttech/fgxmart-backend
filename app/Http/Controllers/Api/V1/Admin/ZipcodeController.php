<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Zipcode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ZipcodeController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);
        $result = Zipcode::with('country:id,name,code')->orderBy('id', 'desc')->paginate($request->integer('per_page', 10));
        // Country choices belong to this feature; no extra countries permission is needed.
        return response()->json(array_merge($result->toArray(), [
            'countries' => Country::orderBy('name')->get(['id', 'name', 'code']),
            'configured_country_ids' => Zipcode::pluck('country_id'),
        ]));
    }

    private function validatedData(Request $request, ?Zipcode $zipcode = null): array
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id', Rule::unique('zipcodes', 'country_id')->ignore($zipcode?->id)],
            'zipcodes' => ['required', 'string', 'max:50000'],
        ]);
        $codes = array_map(fn ($code) => strtoupper(trim(preg_replace('/\s+/', ' ', $code))), explode(',', $data['zipcodes']));
        if (count($codes) > 1000) {
            throw ValidationException::withMessages(['zipcodes' => 'Enter up to 1,000 comma-separated zipcodes per country.']);
        }
        foreach ($codes as $code) {
            if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{0,19}$/D', $code)) {
                throw ValidationException::withMessages(['zipcodes' => 'Each zipcode must be 1–20 letters or digits, with spaces or hyphens allowed. Separate entries with commas and do not leave empty entries.']);
            }
        }
        $data['zipcodes'] = implode(', ', array_values(array_unique($codes)));
        return $data;
    }

    public function store(Request $request)
    {
        $zipcode = Zipcode::create($this->validatedData($request));
        return response()->json(['message' => 'Zipcodes saved successfully', 'data' => $zipcode->load('country:id,name,code')], 201);
    }

    public function update(Request $request, Zipcode $zipcode)
    {
        $zipcode->update($this->validatedData($request, $zipcode));
        return response()->json(['message' => 'Zipcodes updated successfully', 'data' => $zipcode->load('country:id,name,code')]);
    }

    public function destroy(Zipcode $zipcode)
    {
        $zipcode->delete();
        return response()->json(['message' => 'Zipcodes deleted successfully']);
    }
}
