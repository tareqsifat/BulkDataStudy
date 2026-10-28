@if (empty($rows))
    <p class="muted">No rows.</p>
@else
    <div class="scroll">
        <table>
            <thead>
            <tr>
                @foreach (array_keys($rows[0]) as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
