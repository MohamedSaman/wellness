{{--
    Administration → Security: who has two-factor authentication
    (SecuritySettings::users()). Inline styles: Filament's CSS is precompiled.
--}}
<table data-test="two-factor-users" style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
    <thead>
        <tr style="text-align: left; color: rgb(107 114 128);">
            <th style="padding: 0.25rem 1rem 0.25rem 0; font-weight: 500;">User</th>
            <th style="padding: 0.25rem 1rem 0.25rem 0; font-weight: 500;">Role</th>
            <th style="padding: 0.25rem 0; font-weight: 500;">Two-factor</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($users as $user)
            <tr style="border-top: 1px solid rgb(156 163 175 / 0.3);">
                <td style="padding: 0.375rem 1rem 0.375rem 0;">{{ $user['name'] }} <span style="color: rgb(107 114 128);">{{ $user['email'] }}</span></td>
                <td style="padding: 0.375rem 1rem 0.375rem 0;">{{ $user['roles'] }}</td>
                <td style="padding: 0.375rem 0;">
                    @if ($user['two_factor'])
                        <x-filament::badge color="success">On</x-filament::badge>
                    @elseif ($user['required'])
                        <x-filament::badge color="danger">Off: required, blocked until turned on</x-filament::badge>
                    @else
                        <x-filament::badge color="gray">Off</x-filament::badge>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
