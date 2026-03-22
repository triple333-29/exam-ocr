#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
auto_grade_nlp_stream.py — File-based mode (Windows Apache compatible)

รับ argument 2 ตัว:
  python auto_grade_nlp_stream.py <input_file> <output_file>

input_file  : JSON array ของ items [{answer_id, student, key, max_score, q_type}, ...]
output_file : JSON array ของผลลัพธ์ [{answer_id, similarity, score, ...}, ...]

ทำงานเสร็จแล้ว exit 0 — PHP อ่าน output_file เอง
ไม่ใช้ stdin/stdout สำหรับ data เลย (กัน Windows pipe issue)
"""

import sys
import json
import os
import tempfile

# ── กำหนด cache dir ก่อน import ทุกอย่าง ──────────────────────────────────
# Apache service ไม่มี USERPROFILE/HOME → HuggingFace หา cache ไม่เจอ
# ต้องตั้งค่าตรงนี้ก่อน import sentence_transformers หรือ huggingface_hub
_cache_dir = os.path.join(tempfile.gettempdir(), 'hf_cache')
os.makedirs(_cache_dir, exist_ok=True)

os.environ.setdefault('HF_HOME',               _cache_dir)
os.environ.setdefault('HUGGINGFACE_HUB_CACHE',  os.path.join(_cache_dir, 'hub'))
os.environ.setdefault('TRANSFORMERS_CACHE',      os.path.join(_cache_dir, 'hub'))
os.environ.setdefault('HF_DATASETS_OFFLINE',    '1')
os.environ.setdefault('TRANSFORMERS_OFFLINE',   '1')
os.environ.setdefault('USERPROFILE',            tempfile.gettempdir())
os.environ.setdefault('HOME',                   tempfile.gettempdir())
# Windows getpass.getuser() ต้องการ USERNAME — ถ้าไม่มีจะ throw "No username"
os.environ.setdefault('USERNAME',   'apache_worker')
os.environ.setdefault('HOMEDRIVE',  'C:')
os.environ.setdefault('HOMEPATH',   '\\Windows\\Temp')
# ปิด HuggingFace token check ทั้งหมด (ไม่จำเป็นสำหรับ public model)
os.environ['HF_HUB_DISABLE_IMPLICIT_TOKEN'] = '1'
os.environ['HUGGING_FACE_HUB_TOKEN']         = ''
os.environ['HF_TOKEN']                        = ''

# ── ตรวจว่า model cache มีอยู่จริงไหม ──────────────────────────────────────
# ถ้ายังไม่มี cache ให้ปิด offline mode เพื่อ download ครั้งแรก
_hub_dir = os.path.join(_cache_dir, 'hub')
_model_slug = 'sentence-transformers--paraphrase-multilingual-MiniLM-L12-v2'
_model_cached = any(
    _model_slug in d
    for d in os.listdir(_hub_dir)
    if os.path.isdir(os.path.join(_hub_dir, d))
) if os.path.isdir(_hub_dir) else False

if not _model_cached:
    # ยังไม่มี cache → อนุญาตให้ download (ต้องมี internet)
    os.environ.pop('HF_DATASETS_OFFLINE',  None)
    os.environ.pop('TRANSFORMERS_OFFLINE', None)

MODEL_NAME = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"

def die(msg: dict):
    # เขียน error ลง output file (ถ้ามี) แล้ว exit
    out_path = sys.argv[2] if len(sys.argv) >= 3 else None
    if out_path:
        try:
            with open(out_path, 'w', encoding='utf-8') as f:
                json.dump({"fatal": True, **msg}, f, ensure_ascii=False)
        except Exception:
            pass
    sys.exit(1)

# ── ตรวจ arguments ────────────────────────────────────────────────────────────
if len(sys.argv) < 3:
    die({"error": "usage", "detail": "ต้องระบุ input_file และ output_file"})

input_file  = sys.argv[1]
output_file = sys.argv[2]

if not os.path.isfile(input_file):
    die({"error": "input_not_found", "detail": input_file})

# ── โหลด deps ────────────────────────────────────────────────────────────────
try:
    from langdetect import detect as _ld_detect
except Exception:
    _ld_detect = None

try:
    import numpy as np
except Exception as e:
    die({"error": "missing_numpy", "detail": str(e)})

try:
    from sentence_transformers import SentenceTransformer
except Exception as e:
    die({"error": "missing_sentence_transformers", "detail": str(e)})

try:
    import argostranslate.translate
    _argos_ok = True
except Exception:
    _argos_ok = False


# ── helpers ───────────────────────────────────────────────────────────────────
def simple_lang_guess(text: str):
    if not text: return None
    for ch in text:
        if 0x0E00 <= ord(ch) <= 0x0E7F: return "th"
    return "en"

def detect_lang(text: str):
    text = (text or "").strip()
    if not text: return None
    if _ld_detect is None: return simple_lang_guess(text)
    try: return _ld_detect(text)
    except Exception: return simple_lang_guess(text)

def argos_translate(text, src, tgt):
    if not _argos_ok: return text, False, "argos_not_installed"
    try:
        installed = argostranslate.translate.get_installed_languages()
        sl = next((l for l in installed if l.code == src), None)
        tl = next((l for l in installed if l.code == tgt), None)
        if not sl or not tl: return text, False, "argos_lang_not_installed"
        tr = sl.get_translation(tl)
        if not tr: return text, False, "argos_pair_not_installed"
        return tr.translate(text), True, "ok"
    except Exception as e:
        return text, False, f"argos_error:{e}"

def is_short_word(text: str) -> bool:
    """
    คืน True ถ้าคำตอบเป็น 'คำสั้น' ที่ควรใช้ Levenshtein แทน NLP
    เงื่อนไข: ASCII ล้วน + ไม่มี space (1 คำ) + ความยาว <= 15 ตัวอักษร
    เช่น: "cat", "Python", "HTTP", "2024", "yes"
    ไม่รวม: ประโยค, คำไทย, คำที่มีช่องว่าง
    """
    t = text.strip()
    if not t or len(t) > 15:
        return False
    # ต้องเป็น ASCII ล้วน (ไม่มีภาษาอื่น)
    try:
        t.encode('ascii')
    except UnicodeEncodeError:
        return False
    # ต้องเป็น 1 คำ (ไม่มี whitespace)
    if any(c.isspace() for c in t):
        return False
    return True


def levenshtein_sim(a: str, b: str) -> float:
    """คำนวณ similarity จาก Levenshtein distance คืนค่า 0.0–1.0"""
    a = a.strip().lower()
    b = b.strip().lower()
    if a == b:
        return 1.0
    if not a or not b:
        return 0.0
    la, lb = len(a), len(b)
    # dynamic programming
    dp = list(range(lb + 1))
    for i in range(1, la + 1):
        prev = dp[:]
        dp[0] = i
        for j in range(1, lb + 1):
            cost = 0 if a[i-1] == b[j-1] else 1
            dp[j] = min(dp[j] + 1, dp[j-1] + 1, prev[j-1] + cost)
    dist = dp[lb]
    return max(0.0, 1.0 - dist / max(la, lb))


def _parse_fraction(text: str):
    """
    แปลงเศษส่วน เช่น "3/4", "−1/2", "-1/2" → float
    คืน None ถ้าไม่ใช่เศษส่วน
    """
    import re
    text = text.strip().replace('−', '-').replace('–', '-')
    m = re.fullmatch(r'(-?\d+)\s*/\s*(-?\d+)', text)
    if m:
        num, den = int(m.group(1)), int(m.group(2))
        if den == 0:
            return None
        return num / den
    return None


def _parse_number(text: str):
    """
    แปลงข้อความเป็นตัวเลข (float) รองรับ:
      - จำนวนเต็ม / ทศนิยม:  "42", "-3.14", "1,000.5"
      - เลขยกกำลัง:          "2^10", "2**10", "1e6", "1.5e-3"
      - เศษส่วน:             "3/4", "-1/2"
      - เครื่องหมายพิเศษ:    "−" (Unicode minus), "," เป็น thousands separator
    คืน (float, canonical_str) หรือ None ถ้าแปลงไม่ได้
    """
    import re, math
    t = text.strip()
    if not t:
        return None

    # normalize unicode minus/dash → ASCII minus
    t = t.replace('−', '-').replace('–', '-').replace('\u2212', '-')
    # ลบ comma thousands separator เช่น "1,000" → "1000"
    t_no_comma = t.replace(',', '')

    # 1) เศษส่วน
    frac = _parse_fraction(t)
    if frac is not None:
        return frac

    # 2) เลขยกกำลัง: "2^10" หรือ "2**10"
    m = re.fullmatch(r'(-?[\d.]+)\s*(?:\^|\*\*)\s*(-?[\d.]+)', t_no_comma)
    if m:
        try:
            return float(m.group(1)) ** float(m.group(2))
        except Exception:
            return None

    # 3) ตัวเลขปกติ / scientific notation
    try:
        return float(t_no_comma)
    except ValueError:
        pass

    return None


def is_numeric_answer(text: str) -> bool:
    """คืน True ถ้าข้อความนี้ถือว่าเป็น 'คำตอบตัวเลข' ล้วน"""
    return _parse_number(text) is not None


def check_numeric_exact(student_raw: str, key_raw: str) -> dict:
    """
    ตรวจคำตอบตัวเลขแบบเข้มงวด:
      - ต้องเท่ากันทางคณิตศาสตร์ (abs diff ≤ tolerance)
      - key อาจมีหลายค่าที่ยอมรับได้ คั่นด้วย | หรือ ,
      - tolerance: 1e-9 (ปรับได้)
    คืน dict { "match": bool, "student_val": float, "key_vals": [...],
               "sim": 1.0|0.0, "note": str }
    """
    TOLERANCE = 1e-9

    student_val = _parse_number(student_raw)
    if student_val is None:
        return {"match": False, "student_val": None, "key_vals": [],
                "sim": 0.0, "note": "student_not_numeric"}

    # key อาจมีหลายค่า คั่นด้วย | หรือ ,
    import re
    parts = re.split(r'[|,]', key_raw)
    key_vals = []
    for p in parts:
        v = _parse_number(p.strip())
        if v is not None:
            key_vals.append(v)

    if not key_vals:
        return {"match": False, "student_val": student_val, "key_vals": [],
                "sim": 0.0, "note": "key_not_numeric"}

    matched = any(abs(student_val - kv) <= TOLERANCE for kv in key_vals)
    return {
        "match":       matched,
        "student_val": student_val,
        "key_vals":    key_vals,
        "sim":         1.0 if matched else 0.0,
        "note":        "exact_match" if matched else "numeric_mismatch",
    }


def score_from_similarity(sim, max_score, q_type):
    # Threshold เดียวกันทุก type:
    #   sim < 0.50  → 0 คะแนน  (ตอบไม่ถูกหรือไม่เกี่ยวข้อง)
    #   sim >= 0.90 → เต็ม     (ตอบถูกต้องมาก)
    #   0.50–0.90  → proportional linear
    low, high = 0.50, 0.90
    x = max(0.0, min(1.0, (sim - low) / (high - low)))
    return max_score * x, {"low": low, "high": high}


# ── โหลด model ────────────────────────────────────────────────────────────────
try:
    model = SentenceTransformer(MODEL_NAME)
except Exception as e:
    die({"error": "model_load_failed", "detail": str(e)})

# ── อ่าน input ────────────────────────────────────────────────────────────────
try:
    with open(input_file, 'r', encoding='utf-8') as f:
        items = json.load(f)
except Exception as e:
    die({"error": "input_parse_error", "detail": str(e)})

if not isinstance(items, list):
    die({"error": "input_not_array", "detail": "input_file ต้องเป็น JSON array"})

# ── ประมวลผลทีละข้อ ───────────────────────────────────────────────────────────
results = []
for item in items:
    answer_id = item.get("answer_id")
    student   = (item.get("student") or "").strip()
    key       = (item.get("key")     or "").strip()
    max_score = float(item.get("max_score") or 1.0)
    q_type    = item.get("q_type") or ""

    lang_student = detect_lang(student)
    lang_key     = detect_lang(key)
    student_used = student
    translated   = False
    trans_note   = None

    if lang_student and lang_key and lang_student != lang_key:
        t, ok, note = argos_translate(student, lang_student, lang_key)
        trans_note = note
        if ok:
            student_used = t
            translated   = True

    try:
        # ── เลือกวิธีคำนวณ ────────────────────────────────────────────────────
        # 1) key เป็นตัวเลข → ตรวจแบบเข้มงวดเท่านั้น (exact numeric)
        # 2) คำสั้น ASCII 1 คำ                      → Levenshtein
        # 3) ประโยค / ภาษาไทย / หลายคำ              → SentenceTransformers
        use_method   = 'nlp'
        numeric_info = None
        scoring      = None

        if is_numeric_answer(key):
            # ── Numeric exact ─────────────────────────────────────────────────
            use_method   = 'numeric'
            num_result   = check_numeric_exact(student_used, key)
            numeric_info = num_result
            sim          = num_result["sim"]
            # ให้คะแนนแบบ binary: ถูกได้เต็ม, ผิดได้ 0
            score        = max_score if num_result["match"] else 0.0
            scoring      = {"mode": "binary_exact"}

        elif is_short_word(student_used) and is_short_word(key):
            # ── Levenshtein ───────────────────────────────────────────────────
            use_method       = 'levenshtein'
            sim              = levenshtein_sim(student_used, key)
            score, scoring   = score_from_similarity(sim, max_score, q_type)

        else:
            # ── NLP (SentenceTransformers) ────────────────────────────────────
            emb              = model.encode([student_used, key], normalize_embeddings=True)
            sim              = float(np.dot(emb[0], emb[1]))
            score, scoring   = score_from_similarity(sim, max_score, q_type)

        row = {
            "answer_id":        answer_id,
            "similarity":       round(sim, 6),
            "score":            float(score),
            "lang_student":     lang_student,
            "lang_key":         lang_key,
            "translated":       translated,
            "translation_note": trans_note,
            "model":            MODEL_NAME if use_method == 'nlp' else use_method,
            "scoring":          scoring,
            "method":           use_method,
        }
        if numeric_info is not None:
            row["numeric"] = {
                "student_val": numeric_info["student_val"],
                "key_vals":    numeric_info["key_vals"],
                "match":       numeric_info["match"],
                "note":        numeric_info["note"],
            }
        results.append(row)
    except Exception as e:
        results.append({
            "answer_id":        answer_id,
            "similarity":       0.0,
            "score":            0.0,
            "lang_student":     lang_student,
            "lang_key":         lang_key,
            "translated":       False,
            "translation_note": None,
            "model":            MODEL_NAME,
            "error":            str(e),
        })

# ── เขียน output ──────────────────────────────────────────────────────────────
try:
    with open(output_file, 'w', encoding='utf-8') as f:
        json.dump(results, f, ensure_ascii=False)
except Exception as e:
    die({"error": "output_write_error", "detail": str(e)})

sys.exit(0)