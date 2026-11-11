<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ask Ledgerly</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<main class="container">
    <h1>Ask Ledgerly</h1>
    <p>Questions about how Ledgerly works. For your own account, sign in.</p>
    <form id="widget-form" data-challenge-url="{{ route('widget.challenge') }}" data-ask-url="{{ route('widget.ask') }}">
        <input id="widget-question" type="text" maxlength="{{ config('ai.widget.max_question_chars') }}" placeholder="How do late fees work?" required>
        <button type="submit">Ask</button>
    </form>
    <p id="widget-answer" aria-live="polite"></p>
    <ul id="widget-sources"></ul>
</main>
<script src="{{ asset('js/widget.js') }}"></script>
</body>
</html>
