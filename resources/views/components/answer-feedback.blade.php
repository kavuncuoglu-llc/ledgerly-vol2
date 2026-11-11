@props(['replyId', 'rating' => null])

<p
    class="feedback"
    x-data="{
        rating: @js($rating),
        asking: false,
        async send(rating, reason = null) {
            await fetch('/replies/{{ $replyId }}/feedback', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ rating, reason }),
            });

            this.rating = rating;
            this.asking = false;
        },
    }"
>
    <template x-if="rating !== null && !asking">
        <span>Thanks for the feedback.</span>
    </template>

    <template x-if="rating === null && !asking">
        <span>
            <button type="button" class="link-button" x-on:click="send('up')">Helpful</button>
            <button type="button" class="link-button" x-on:click="asking = true">Not helpful</button>
        </span>
    </template>

    <template x-if="asking">
        <span>
            What went wrong?
            <button type="button" class="link-button" x-on:click="send('down', 'wrong_answer')">Wrong answer</button>
            <button type="button" class="link-button" x-on:click="send('down', 'did_not_answer')">Did not answer my question</button>
            <button type="button" class="link-button" x-on:click="send('down', 'wanted_person')">I wanted a person</button>
        </span>
    </template>
</p>
