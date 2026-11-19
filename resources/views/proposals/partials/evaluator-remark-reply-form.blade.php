@php($replyErrors = $errors->getBag($errorBag))
<details class="inline-form evaluator-remark-reply-form" @if($replyErrors->any()) open @endif>
    <summary class="icon-action evaluator-remark-action" aria-label="{{ $label }}" title="{{ $label }}">
        <i class="fa-solid fa-reply" aria-hidden="true"></i>
    </summary>
    <form method="POST" action="{{ $url }}">
        @csrf
        <label>{{ $label }}
            <textarea name="content" rows="3" maxlength="2000" required @if($replyErrors->has('content')) aria-invalid="true" aria-describedby="{{ $errorBag }}-error" @endif>{{ $replyErrors->any() ? old('content') : '' }}</textarea>
        </label>
        @if($replyErrors->has('content'))
            <small class="field-error" id="{{ $errorBag }}-error">{{ $replyErrors->first('content') }}</small>
        @endif
        <div class="remark-form-actions">
            <button class="btn primary" type="submit">Post reply</button>
        </div>
    </form>
</details>
