<?php

namespace App\Services;

class ActiveAcademicTermService
{
    public function __construct(private readonly StudentApiService $api, private readonly AcademicTermService $terms) {}

    /** @return array{term: string, year: string, semester: string, source: string, notice: ?string} */
    public function resolve(): array
    {
        $known = $this->api->isSdl() ? $this->api->latestKnownTerm() : null;
        $defaults = $this->terms->defaults($known);

        return [...$defaults, 'term' => $defaults['semester'].'/'.$defaults['year'],
            'source' => $known !== null ? 'sdl' : 'calendar',
            'notice' => $known === null ? 'ยังไม่มีภาคเรียนล่าสุดที่ตรวจยืนยันจาก SDL School จึงใช้ภาคเรียนตามปฏิทิน กรุณาตรวจแหล่งข้อมูลก่อนเริ่มภาคเรียนใหม่' : null];
    }
}
