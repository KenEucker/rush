<div class="bg-white rounded p-4">
    <p>You are signed in with an active Management membership.</p>
    <p>Configure seasons, dated phases, time zones and calendar weeks. A server connection is required.</p>
    <ul>
        @foreach($organizations as $organization)
            <li><a href="{{ route('platform.seasons', [$organization->id, 'new']) }}">Seasons and phases — {{ $organization->name }}</a></li>
        @endforeach
    </ul>
    <a href="/">Return to RUSH</a>
</div>
