<?php

namespace App\Eligibility;

/**
 * Sorts the wording the site can't check into requirements and the rest.
 *
 * A requirement, such as "Calon Tidak Buta Warna" or a subject list the collectors couldn't read, may stop the
 * applicant from entering, so a course with one isn't shown as one they qualify for. The rest doesn't change that:
 * - information, such as where the course runs or a course to take after entering;
 * - an interview or admission test, such as MEdSI or a drawing test, which every applicant sits after applying.
 *   The courses page has a filter for these, and its "You qualify" group says some courses ask for one.
 */
final class RequirementNotes
{
    /**
     * Conditions about the applicant themselves: health, disability, or speaking a language. These are requirements
     * even beside information, e.g. "Lulus Saringan Kesihatan Yang Akan Dijalankan Di Unisza ...", or beside an
     * interview, e.g. "Temu Bual Calon Tidak Mempunyai Ketidakupayaan ...".
     */
    private const PERSONAL_CONDITIONS = '/ketidakupayaan|kurang\s+upaya|kecacatan|\bsihat\b|kesihatan|penyakit|\bbuta\b|rabun|pekak|penglihatan|saringan\s+hiv|penutur/iu';

    /**
     * Headings over the lines that follow them, e.g. "Bagi Calon Lepasan Diploma / Setaraf,", "Calon Yang Tidak" or
     * "Ub6526001 Matrikulasi / Asasi".
     */
    private const HEADING = '/^(bagi\s+(calon\s+)?lepasan\b[^.]*|u[a-z]\d{7}\b[^.]*|mendapat\s+sekurang-?kurangnya(\s+tahap)?\s+seperti\s+berikut|calon\s+yang\s+tidak|catatan|nota)\s*[:,.;]?$/iu';

    /**
     * An IELTS, TOEFL, PTE or CEFR score, which stands in for MUET for applicants without it. The form always asks for
     * MUET, so it is information, unless MUET is in the same line.
     */
    private const ENGLISH_TEST = '/^(?!.*\bmuet\b).*(\bielts\b|\btoefl\b|pearson\s+test|\bpte\b|\bcefr\b)/iu';

    /**
     * A grade or band to get, e.g. "Mendapat Sekurang-Kurangnya Kepujian Gred C Di Peringkat SPM Bagi Mana-Mana Dua (2)
     * Mata Pelajaran Berikut ...". It is a requirement even when information follows it in the same line, unless it
     * only says who takes a course after entering ("... Perlu Mengikuti Mata Pelajaran Pengukuhan ...").
     */
    private const GRADE_TO_GET = '/^mendapat\s+sekurang-?kurangnya\b(?!.*(pengukuhan|perlu\s+mengikuti))/iu';

    /**
     * Wording that doesn't decide entry.
     *
     * @var list<string>
     */
    private const INFORMATION = [
        // No promise of a teaching post after graduating, or the course being open-market
        '/tiada\s+jaminan|open\s+market|pasaran\s+terbuka|^\(?\d?\)?\s*untuk\s+makluman/iu',
        // Credit transfer, the length of study and fees
        '/(pemindahan|pengecualian)\s+kredit|tempoh\s+pengajian\s+sebenar|yuran/iu',
        // Documents sent with the application
        '/transkrip|muat\s+naik\s+keputusan|keputusan\s+peperiksaan\s+yang\s+lengkap|salinan\s+(diploma|sijil|ijazah)/iu',
        // Where the course runs, and what kind of course it is
        '/dijalankan\s+di\b|^penempatan\s*:|program\s+2u2i|dual\s+ijazah|ijazah\s+dual|ijazah\s+tunggal|dikendalikan\s+sepenuhnya\s+dalam\s+bahasa|tidak\s+boleh\s+mengamal|^program\s+(bukan\s+)?pendidikan\.?$|pra\s+pendaftaran/iu',
        // What happens after entering, e.g. "Calon Yang Tidak Mengambil Physics ... Dikehendaki Mengambil Mata Pelajaran
        // Basic Physics Di Universiti" or "Calon-Calon Yang Diterima Masuk DAN Mendaftar ... Perlu Menduduki Ujian ..."
        '/pengukuhan|di\s+universiti\.?\)?$|dikecualikan\s+daripada\s+mengambil|yang\s+diterima\s+masuk|peringkat\s+sarjana\s+muda|sebelum\s+dibenarkan\s+mengikuti|tidak\s+memerlukan\s+kursus|akan\s+mengikut/iu',
        // Preferences and suggestions, not conditions
        '/keutamaan\s+(akan\s+)?diberi|digalakkan/iu',
        // The Senate's other conditions "from time to time", which every course has
        '/syarat(-syarat)?\s+lain\s+yang\s+dit\w+\s+(oleh\s+)?senat/iu',
        // Another qualification the Senate accepts, which is another way in rather than something more to meet
        '/^(mempunyai|memiliki)\s+kelulusan\s+lain\b.*\bsenat\b[^.;]*[.;]?$/iu',
    ];

    /**
     * An interview or admission test, e.g. "Lulus Ujian DAN / ATAU Temu Duga Yang Ditetapkan Oleh Universiti Awam.", but
     * not the "Tarikh Keputusan Ujian Dikeluarkan" of a MUET result.
     */
    private const ADMISSION_TEST = '/temu\s*-?\s*(duga|bual)|\bujian\b(?!\s+dikeluar)|uji\s+bakat|\bmedsi\b|portfolio/iu';

    /**
     * Words that make a line with an interview or test ask for more, e.g. "Lulus Ujian DAN / ATAU Temu Duga Yang
     * Ditetapkan Oleh Fakulti. Calon Lepasan Aliran Perakaunan (Matrikulasi KPM)."
     */
    private const MORE_THAN_A_TEST = '/peringkat\s+(spm|stpm|matrikulasi|asasi|diploma)|\bgred\s+[a-e]\b|mata\s+pelajaran|\baliran\b|berkemahiran|alat\s+muzik|kumpulan|matematik/iu';

    /**
     * Whether a line of wording is a requirement for entry the site can't check, rather than information about the
     * course or an interview or test.
     */
    public function isRequirement(string $note): bool
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        if (preg_match(self::PERSONAL_CONDITIONS, $note) === 1) {
            return true;
        }

        if (preg_match(self::HEADING, $note) === 1 || preg_match(self::ENGLISH_TEST, $note) === 1) {
            return false;
        }

        if (preg_match(self::GRADE_TO_GET, $note) === 1) {
            return true;
        }

        foreach (self::INFORMATION as $pattern) {
            if (preg_match($pattern, $note) === 1) {
                return false;
            }
        }

        return preg_match(self::ADMISSION_TEST, $note) !== 1 || preg_match(self::MORE_THAN_A_TEST, $note) === 1;
    }
}
