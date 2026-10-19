<?php

namespace App\Catalog\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        $kind = $this->route()->defaults['catalog_kind'] ?? 'product';
        $creating = in_array($action, ['store', 'storeVariant', 'upload'], true);
        $page = ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        if (in_array($action, ['index', 'publicIndex'], true)) {
            return $page + ($kind === 'product' ? [
                'search' => ['sometimes', 'string', 'max:150'],
                'category' => ['sometimes', 'string', 'max:180'],
                'tag' => ['sometimes', 'string', 'max:120'],
                'min_price' => ['sometimes', 'numeric', 'min:0'],
                'max_price' => ['sometimes', 'numeric', 'min:0', Rule::when($this->has('min_price'), 'gte:min_price')],
                'availability' => ['sometimes', Rule::in(['in_stock', 'out_of_stock', 'low_stock'])],
                'sort' => ['sometimes', Rule::in(['id', '-id', 'name', '-name', 'price', '-price', 'created_at', '-created_at'])],
                ...($action === 'index' ? ['status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])]] : []),
            ] : []);
        }
        if (in_array($action, ['stock', 'history'], true)) {
            return $page + ($action === 'stock' ? ['availability' => ['sometimes', Rule::in(['in_stock', 'out_of_stock', 'low_stock'])]] : []);
        }
        if ($action === 'adjust') {
            return [
                'quantity_change' => ['required', 'integer', 'between:-100000000,100000000', 'not_in:0'],
                'reason' => ['required', 'string', 'max:500'],
                'operation_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
                'order_id' => ['sometimes', 'nullable', 'integer', 'exists:orders,id'],
            ];
        }
        if ($action === 'reserve') {
            return ['order_id' => ['required', 'integer', 'exists:orders,id'], 'quantity' => ['required', 'integer', 'min:1', 'max:100000000']];
        }
        if (in_array($action, ['release', 'deduct'], true)) {
            return ['order_id' => ['required', 'integer', 'exists:orders,id']];
        }
        if (in_array($action, ['storeVariant', 'updateVariant'], true)) {
            $required = $creating ? 'required' : 'sometimes';

            return [
                'sku' => [$required, 'string', 'max:100', 'regex:/^[a-zA-Z0-9._-]+$/'],
                'attributes' => ['sometimes', 'array', 'max:20'],
                'attributes.*' => ['required', 'string', 'max:100'],
                'price' => [$required, 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                'sale_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                'sale_starts_at' => ['sometimes', 'nullable', 'date'],
                'sale_ends_at' => ['sometimes', 'nullable', 'date'],
                'cost_price' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
                'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
                'weight_grams' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
                'is_active' => ['sometimes', 'boolean'],
                'allow_backorders' => ['sometimes', 'boolean'],
                'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            ];
        }
        if (in_array($action, ['upload', 'updateImage'], true)) {
            return [
                ...($action === 'upload' ? ['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.config('catalog.upload_max_kb')]] : []),
                'variant_id' => ['sometimes', 'nullable', 'integer'],
                'alt_text' => ['sometimes', 'nullable', 'string', 'max:255'],
                'is_primary' => ['sometimes', 'boolean'],
                'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            ];
        }
        if (in_array($action, ['store', 'update'], true)) {
            $required = $creating ? 'required' : 'sometimes';
            if ($kind === 'product') {
                return [
                    'name' => [$required, 'string', 'max:200'],
                    'slug' => [$required, 'string', 'max:220', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                    'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
                    'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
                    'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
                    'category_ids' => ['sometimes', 'array', 'max:100'],
                    'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
                    'tag_ids' => ['sometimes', 'array', 'max:100'],
                    'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
                ];
            }

            return [
                'name' => [$required, 'string', 'max:'.($kind === 'category' ? 150 : 100)],
                'slug' => [$required, 'string', 'max:'.($kind === 'category' ? 180 : 120), 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
                ...($kind === 'category' ? [
                    'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
                    'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
                    'is_active' => ['sometimes', 'boolean'],
                    'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
                ] : []),
            ];
        }

        return [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), [...$allowed, '_token']) as $field) {
                $validator->errors()->add($field, 'This field is not allowed.');
            }
        }];
    }
}
