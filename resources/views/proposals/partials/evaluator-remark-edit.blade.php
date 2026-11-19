@php($editErrors = $errors->getBag($errorBag))
<details class="inline-form evaluator-remark-reply-form" @if($editErrors->any()) open @endif>
    @if(isset($icon))
        <summary class="icon-action evaluator-remark-action" aria-label="{{ $label }}" title="{{ $label }}">
            <i class="fa-solid {{ $icon }}" aria-hidden="true"></i>
        </summary>
    @else
        <summary>{{ $label }}</summary>
    @endif
    <form method="POST" action="{{ $url }}">
        @csrf
        @method('PATCH')
        <label>{{ $label }}
            <textarea name="{{ $field }}" rows="3" maxlength="{{ $maxLength }}" required @if($editErrors->has($field)) aria-invalid="true" aria-describedby="{{ $errorBag }}-error" @endif>{{ $editErrors->any() ? old($field, $value) : $value }}</textarea>
        </label>
        @if($editErrors->has($field))
            <small class="field-error" id="{{ $errorBag }}-error">{{ $editErrors->first($field) }}</small>
        @endif
        <div class="remark-form-actions">
            <button class="btn primary" type="submit">Save changes</button>
        </div>
    </form>
</details>
