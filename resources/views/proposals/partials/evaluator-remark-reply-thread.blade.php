<div class="reply evaluator-remark-reply notification-target" id="evaluator-remark-reply-{{ $reply->id }}">
    <div class="remark-meta">
        <b>{{ $reply->user->full_name }}</b>
        <time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->format('M d, Y g:i A') }}</time>
    </div>
    <p>{{ $reply->content }}</p>
    @if($canReplyToRemarks)
        <div class="evaluator-reply-actions">
            @if((int) $reply->user_id === (int) auth()->id())
                @include('proposals.partials.evaluator-remark-edit', [
                    'url' => route('proposals.evaluator-remarks.replies.update', [$proposal, $remark, $reply]),
                    'label' => 'Edit reply', 'field' => 'content', 'value' => $reply->content, 'maxLength' => 2000,
                    'errorBag' => 'editEvaluatorRemarkReply'.$reply->id, 'icon' => 'fa-pen',
                ])
            @endif
            @include('proposals.partials.evaluator-remark-reply-form', [
                'url' => route('proposals.evaluator-remarks.replies.nested.store', [$proposal, $remark, $reply]),
                'label' => 'Reply to this reply', 'errorBag' => 'nestedEvaluatorRemarkReply'.$reply->id,
            ])
        </div>
    @endif
</div>
@if($repliesByParent->has($reply->id))
    <div @class(['evaluator-reply-children' => $depth < 2])>
        @foreach($repliesByParent->get($reply->id) as $child)
            @include('proposals.partials.evaluator-remark-reply-thread', ['reply' => $child, 'depth' => $depth + 1])
        @endforeach
    </div>
@endif
