<form method="POST" action="registercrews">
    @csrf
    <h1>Register discipline(s) for Team</h1>
    <p>
    <label for="teams">Choose Team:</label>
    <select id="teams" name="teams">
        @foreach ($teams as $team)
            <option value={{ $team->id }}>{{ $team->name }}</option>
        @endforeach
    </select>
    </p>
    {{-- <input type="text" name="selectedFruit" id="selectedFruit" value=""> --}}
    <p>
    <label for="disciplines">Choose Disciplines:</label>
    <p>
        @foreach ($disciplines as $discipline)
                @php
                $isActive = false;
                $teamId = 1;
                @endphp
                @foreach ($crews as $crew) 
                    @if ($crew->discipline_id == $discipline->id)
                        @if ($crew->team_id == $teamId)
                            @php
                            $isActive = true;
                            @endphp
                        @endif
                    @endif
                @endforeach
            
            <input type="checkbox" id={{ $discipline->id }} name="discipline[]".{{ $discipline->id }} value={{ $discipline->id }} @checked($isActive) />
            <label for={{ $discipline->id }}> {{ $discipline->boat_group }} {{ $discipline->age_group }} {{ $discipline->gender_group }} {{ $discipline->distance }}</label><br>
        @endforeach
    </select>
    </p>

    <div>
        <button type="submit">APPLY</button>
    </div>
</form>
{{-- <script>
    document.addEventListener('DOMContentLoaded', function() {
        var selectElement = document.querySelector('select[name="teams"]');
        var textBoxElement = document.querySelector('#selectedFruit');

        selectElement.addEventListener('change', function() {
            var selectedValue = this.value;
            textBoxElement.value = selectedValue;
        });
    });
</script> --}}