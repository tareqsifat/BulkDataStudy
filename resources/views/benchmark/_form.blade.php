<form class="card grid" method="get" action="{{ $action }}" data-ajax>
    @isset($scenarios)
        @if ($showScenario ?? true)
            <label>Scenario
                <select name="scenario">
                    @foreach ($scenarios as $key => $label)
                        <option value="{{ $key }}" @selected($params['scenario'] === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    @endisset
    @isset($bases)
        <label>Base driver
            <select name="base">
                @foreach ($bases as $base)
                    <option value="{{ $base }}" @selected($params['base'] === $base)>{{ $base }}</option>
                @endforeach
            </select>
        </label>
        <label>Cache store
            <select name="cache_store">
                @foreach ($stores as $store)
                    <option value="{{ $store }}" @selected($params['cache_store'] === $store)>{{ $store }}</option>
                @endforeach
            </select>
        </label>
    @endisset
    @if ($showRuns ?? true)
        <label>Timed runs <input type="number" name="runs" min="1" max="50" value="{{ $params['runs'] }}"></label>
    @endif
    <label>id (find) <input type="number" name="id" min="1" value="{{ $params['id'] }}"></label>
    <label>user_id (user) <input type="number" name="user_id" min="1" value="{{ $params['user_id'] }}"></label>
    <label>department_id <input type="number" name="department_id" min="1" value="{{ $params['department_id'] }}"></label>
    <label>pay_period <input type="text" name="pay_period" value="{{ $params['pay_period'] }}" placeholder="2025-06-01"></label>
    <label>page (offset) <input type="number" name="page" min="1" value="{{ $params['page'] }}"></label>
    <label>after_id (keyset) <input type="number" name="after_id" min="0" value="{{ $params['after_id'] }}"></label>
    <label>per_page <input type="number" name="per_page" min="1" max="1000" value="{{ $params['per_page'] }}"></label>
    <label>from_user (summary) <input type="number" name="from_user" min="1" value="{{ $params['from_user'] }}"></label>
    <label>users (summary) <input type="number" name="users" min="1" value="{{ $params['users'] }}"></label>
    @isset($selected)
        <div class="checks">
            @foreach ($patterns as $key => $label)
                <label><input type="checkbox" name="patterns[]" value="{{ $key }}" @checked(in_array($key, $selected, true))> {{ $label }}</label>
            @endforeach
        </div>
    @endisset
    <div><button type="submit">Run</button> <span class="muted" data-status></span></div>
</form>
