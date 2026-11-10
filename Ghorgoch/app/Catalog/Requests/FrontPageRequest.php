<?php

namespace App\Catalog\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FrontPageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->route()->getActionMethod() === 'upload') {
            return ['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:'.config('catalog.upload_max_kb')]];
        }
        if (! $this->isMethod('PATCH')) {
            return [];
        }
        $rules = [
            'version' => ['required', 'integer', 'min:0'],
            'content' => ['required', 'array:hero,categories,featured,new_arrivals,promotions,benefits,faq'],
            'content.hero' => ['required', 'array:visible,heading,slides'],
            'content.hero.heading' => ['required', 'string', 'max:250'],
            'content.hero.slides' => ['present', 'array', 'max:10', Rule::when($this->boolean('content.hero.visible'), 'min:1')],
            'content.hero.slides.*' => ['array:id,image,image_path,alt,eyebrow,title,accent,description,href,action'],
            'content.categories' => ['required', 'array:visible,title,subtitle,ids'],
            'content.categories.subtitle' => ['nullable', 'string', 'max:1000'],
            'content.featured' => ['required', 'array:visible,title,ids'],
            'content.new_arrivals' => ['required', 'array:visible,title,ids,mode,limit'],
            'content.new_arrivals.mode' => ['required', Rule::in(['latest', 'selected'])],
            'content.new_arrivals.limit' => ['required', 'integer', 'min:1', 'max:24'],
            'content.promotions' => ['required', 'array:visible,cards'],
            'content.promotions.cards' => ['present', 'array', 'max:4'],
            'content.promotions.cards.*' => ['array:id,image,image_path,alt,eyebrow,title,description,href,action'],
            'content.benefits' => ['required', 'array:visible,items'],
            'content.benefits.items' => ['present', 'array', 'max:8'],
            'content.benefits.items.*' => ['array:id,icon,title,text'],
            'content.benefits.items.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'content.benefits.items.*.icon' => ['required', Rule::in(['ShoppingBag', 'PackageCheck', 'Heart', 'Search'])],
            'content.benefits.items.*.title' => ['required', 'string', 'max:250'],
            'content.benefits.items.*.text' => ['nullable', 'string', 'max:1000'],
            'content.faq' => ['required', 'array:visible,title,description,items'],
            'content.faq.title' => ['required', 'string', 'max:250'],
            'content.faq.description' => ['nullable', 'string', 'max:2000'],
            'content.faq.items' => ['present', 'array', 'max:30'],
            'content.faq.items.*' => ['array:id,question,answer'],
            'content.faq.items.*.id' => ['required', 'string', 'max:100', 'distinct'],
            'content.faq.items.*.question' => ['required', 'string', 'max:500'],
            'content.faq.items.*.answer' => ['required', 'string', 'max:5000'],
        ];
        foreach (['hero', 'categories', 'featured', 'new_arrivals', 'promotions', 'benefits', 'faq'] as $section) {
            $rules['content.'.$section.'.visible'] = ['required', 'boolean'];
        }
        foreach (['categories' => 'categories', 'featured' => 'products', 'new_arrivals' => 'products'] as $section => $table) {
            $rules['content.'.$section.'.title'] = ['required', 'string', 'max:250'];
            $rules['content.'.$section.'.ids'] = ['present', 'array', 'max:100'];
            $rules['content.'.$section.'.ids.*'] = ['integer', 'distinct', 'exists:'.$table.',id'];
        }
        foreach (['hero.slides', 'promotions.cards'] as $group) {
            foreach (['id', 'title', 'image', 'href', 'action'] as $field) {
                $rules['content.'.$group.'.*.'.$field] = ['required', 'string', 'max:2000'];
            }
            $rules['content.'.$group.'.*.id'][] = 'distinct';
            foreach (['alt', 'eyebrow', 'description'] as $field) {
                $rules['content.'.$group.'.*.'.$field] = ['nullable', 'string', 'max:2000'];
            }
            $rules['content.'.$group.'.*.image'][] = 'regex:~^(?:/(?!/)|https?://)~i';
            $rules['content.'.$group.'.*.href'][] = 'regex:~^(?:/(?!/)|https?://|#[A-Za-z][A-Za-z0-9_.:-]*$)~i';
            $rules['content.'.$group.'.*.image_path'] = ['nullable', 'string', 'regex:~^catalog/[a-f0-9-]+\.png$~i'];
        }
        $rules['content.hero.slides.*.accent'] = ['nullable', 'string', 'max:250'];

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'content.hero.slides.*.href.regex' => 'The slide button link must be a page path, an HTTP(S) URL, or an anchor such as #collection.',
            'content.promotions.cards.*.href.regex' => 'The promotion button link must be a page path, an HTTP(S) URL, or an anchor such as #collection.',
        ];
    }
}
