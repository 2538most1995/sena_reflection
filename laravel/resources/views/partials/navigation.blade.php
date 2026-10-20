<a class="skip-link" href="#main-content">ข้ามไปยังเนื้อหา</a>
<header class="app-header">
    <div class="header-inner">
        <a class="brand" href="{{ route('dashboard') }}" aria-label="SENA Reflection หน้าหลัก">
            <span class="brand-mark" aria-hidden="true">SR</span>
            <span class="brand-copy">SENA Reflection<small>ระบบบันทึกหลังการสอน</small></span>
        </a>

        <nav class="primary-nav" aria-label="เมนูหลัก">
            <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard') || request()->routeIs('records.*')])>ภาพรวม</a>
            <a href="{{ route('students') }}" @class(['active' => request()->routeIs('students')])>ข้อมูลนักศึกษา</a>
            @if(auth()->user()->isDistrict())
                <a href="{{ route('admin') }}" @class(['active' => request()->routeIs('admin*')])>จัดการระบบ</a>
            @endif
        </nav>

        <details class="account-menu">
            <summary>
                <span class="avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                <span class="account-copy"><b>{{ auth()->user()->name }}</b><small>{{ auth()->user()->isDistrict() ? 'ผู้ดูแลระดับอำเภอ' : 'ผู้ใช้งานระดับตำบล' }}</small></span>
            </summary>
            <div class="account-popover">
                <a href="{{ route('profile.edit') }}">โปรไฟล์และรหัสผ่าน</a>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">ออกจากระบบ</button>
                </form>
            </div>
        </details>
    </div>
</header>
