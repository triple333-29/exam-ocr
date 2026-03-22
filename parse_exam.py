import argparse
import json
import sys
from pathlib import Path
import re
import os
from collections import Counter

def parse_exam_to_json(md_filename='exam_cleaned_output.md', output_filename='exam_data.json'):
    if not os.path.exists(md_filename):
        print(f"File not found: {md_filename}")
        return

    with open(md_filename, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    questions = []
    current_q = None
    current_section = 1 
    pending_context = "" 
    
    section_pat = re.compile(r'ส่วนที่\s*(\d+)')
    
    #ปรับ Regex ให้รองรับ Markdown Bold/Italic หน้าตัวเลข (เช่น **10. หรือ __10.)
    q_pat = re.compile(r'^\s*(?:(?:\*\*|__|•|-)\s*)?(\d+(?:\.\d+)?)(?:\\)?\.\s*(.*)')
    
    # เพิ่ม Pattern ดักจับกรณีมีตัวหนาเฉพาะเลขข้อ เช่น "**10.** ข้อใด..."
    q_pat_bold_num = re.compile(r'^\s*\*\*(\d+(?:\.\d+)?)\.?\*\*(?:\s*\.)?\s*(.*)')

    item_pat = re.compile(r'^\s*(?:(?:_+)?\s*-?\s*\(?([a-zA-Zก-ฮ])[\)\.]|(?:_+)?\s*-?\s*(\d+(?:\.\d+)*)\.?|[-•*]|_+)\s*(.*)')
    
    ignore_pat = re.compile(r'^(## Page|---|รหัส|รหัสนักศึกษา|ลำดับที่|\.{10,}|คณะวิทยาศาสตร์|ข้อสอบกลางภาค|ข้อสอบปลายภาค|สอบวัน)')

    subjective_keywords = [
        "ข้อละ", "อธิบาย", "เขียน", "วาด", "ระบุ", "วิเคราะห์", "เปรียบเทียบ", 
        "ยกตัวอย่าง", "จับคู่", "เติม", "ตอบคำถาม", "บรรยาย", "จง", "สร้างฟังก์ชัน", "เหตุผล"
    ]
    
    common_sub_header_keywords = [
        "ผลรัน", "ลำดับการทำงาน", "output", "answer", "example", "ตัวอย่าง", "กรณี", "วิธีทำ", "ขั้นตอน",
        "syntax", "รูปแบบ"
    ]

    instruction_verbs = [
        "ใช้ตอบคำถาม", "จากรูป", "กำหนดให้", "ใช้ข้อมูล", 
        "จาก code", "จากตาราง", "Consider", "Using", "ใช้ค่า"
    ]

    def clean_underscores(text):
        if not text: return ""
        text = text.replace("\\_", "_")
        text = text.replace(r"\_", "_") 
        text = re.sub(r'(?:_|\.|\s[_\.]){3,}', '______', text)    
        return text
    
    #คาดเดาปรัเภทโจทย์
    def is_subjective_question(text):
        text = text.strip()
        for kw in subjective_keywords:
            if kw in text: return True
        return False

    #ตรวจหาข้อย่อย
    def is_common_sub_header(text):
        text_lower = text.lower()
        return any(k in text_lower for k in common_sub_header_keywords)

    #หาcode
    def looks_like_code(text):
        text = text.strip()
        if re.search(r'[ก-๙]', text):
            if '#' in text:
                parts = text.split('#', 1)
                if re.search(r'[ก-๙]', parts[0]): return False
            elif '//' in text:
                parts = text.split('//', 1)
                if re.search(r'[ก-๙]', parts[0]): return False
            else: 
                return False

        if re.search(r'(=|\[|\]|\(|\)|def\s|return|import|print|class\s|\sfor\s|\swhile\s|\sif\s|\srange\()', text): return True
        if re.search(r'(\+=|-=|\*=|/=|==|!=|<=|>=)', text): return True
        if text.startswith('`') or text.endswith('`'): return True
        
        c_keywords = [
            r'\bint\s', r'\bfloat\s', r'\bdouble\s', r'\bchar\s', r'\bvoid\s', r'\bbool\s', 
            r'\bstruct\s', r'\bpublic:', r'\bprivate:', r'\bprotected:',
            r'#include', r'std::', r'printf', r'scanf', r'cout', r'cin', r'System\.out', 
            r'Console\.Write', r'\busing\s+namespace', r'\bnamespace\s'
        ]
        if any(re.search(kw, text) for kw in c_keywords): return True
        if re.search(r'(\{|}|\->|::|;)', text): return True 
        if text.startswith('//') or text.startswith('/*'): return True 

        return False

    #แยกคำชี้แจงออก
    def is_instruction_line(text):
        text = text.strip()
        if not text: return False
        
        if item_pat.match(text): return False
        # เช็ค pattern คำถามทั่วไป
        if q_pat.match(text) or q_pat_bold_num.match(text): return False
        
        if re.search(r'[\(\[]\s*\d+\s*(คะแนน|Marks|Points)\s*[\)\]]', text, re.IGNORECASE):
            return True
        if text.startswith("CLO"):
            return True
        for verb in instruction_verbs:
            if verb in text:
                return True     
        return False

    #คาดเดาการหายของตัวเลือก
    def finalize_question(q, all_prev_qs):
        if not q: return None
        q['text'] = clean_underscores(q['text'].strip())
        
        #ลบเครื่องหมาย Markdown ตกค้างในตัวคำถาม
        q['text'] = re.sub(r'(?:\*\*|__)$', '', q['text']).strip() # ลบตัวหนาท้ายประโยค
        q['text'] = re.sub(r'^\*\*', '', q['text']).strip() # ลบตัวหนาต้นประโยค
        if q['options']: q['type'] = 'multiple_choice'
        elif q['sub_items']: q['type'] = 'subjective_subparts'
        else: q['type'] = 'subjective'

        if q['section'] == 1:
            notes = []
            if q.get('note'): notes.append(q['note'])
            opts_count = len(q['options'])
            is_subj_text = is_subjective_question(q['text'])
            if opts_count == 0 and not q['sub_items'] and not is_subj_text:
                 if int(float(q['id'])) < 30: 
                    notes.append("ตัวเลือกหายหมด (All options missing)")
            elif opts_count > 0:
                recent_qs = [len(prev['options']) for prev in all_prev_qs 
                             if prev['section'] == q['section'] and prev.get('options')]
                if recent_qs:
                    last_5 = recent_qs[-5:]
                    if last_5:
                        mode_count = Counter(last_5).most_common(1)[0][0]
                        if opts_count < mode_count:
                            notes.append(f"ตัวเลือกอาจไม่ครบ")
            if notes: q['note'] = ", ".join(notes)
        return q
    
    #จับข้อย่อยในกรณีถูกอ่านว่าเป็นตัวเลือก/เพิ่มข้อย่อย
    def add_sub_item(q, content):
        content = clean_underscores(content)
        if q['options']:
            formatted_options = "\n".join([f"- ({opt['label']}) {opt['text']}" for opt in q['options']])
            q['text'] += "\n" + formatted_options
            q['options'] = [] 
        q['sub_items'].append(content)

    for line in lines:
        line_stripped = line.strip()
        if not line_stripped: continue
        if ignore_pat.match(line_stripped): continue

        sec_match = section_pat.search(line_stripped)
        if sec_match:
            current_section = int(sec_match.group(1))
            continue 

        if is_instruction_line(line_stripped):
            if current_q:
                questions.append(finalize_question(current_q, questions))
                current_q = None
            pending_context += line_stripped + "\n"
            continue

        # ตรวจสอบว่าเป็นคำถามหรือไหม
        q_match = q_pat.match(line_stripped)
        if not q_match:
            q_match = q_pat_bold_num.match(line_stripped)

        is_new_q = False
        
        if q_match:
            q_id_str = q_match.group(1)
            q_text = q_match.group(2).strip()
            
            # ลบ Markdown ท้ายประโยคออก
            q_text = re.sub(r'(?:\*\*|__)$', '', q_text).strip()

            if re.match(r'^/?[\d.]+$', q_text): 
                is_new_q = False
            elif current_q:
                try:
                    curr_id = float(current_q['id'])
                    new_id = float(q_id_str)
                    
                    looks_code = looks_like_code(line_stripped)
                    has_thai = re.search(r'[ก-๙]', q_text)
                    has_instruction = any(k in q_text for k in instruction_verbs + subjective_keywords)
                    
                    is_sub_part = False
                    if '.' in q_id_str and '.' not in current_q['id']:
                        if q_id_str.startswith(current_q['id'] + '.'):
                            is_sub_part = True
                    
                    if is_sub_part: is_new_q = False 
                    elif looks_code and not has_thai and not has_instruction: is_new_q = False 
                    elif current_q['section'] != current_section: is_new_q = True 
                    elif new_id == curr_id + 1: is_new_q = True
                    elif new_id > curr_id: is_new_q = True
                    elif new_id == curr_id:
                        if not looks_code: is_new_q = True 
                    elif new_id < curr_id:
                        if looks_code or is_common_sub_header(q_text): is_new_q = False
                        elif '.' in q_id_str and not q_id_str.endswith('.'): is_new_q = False
                        elif (curr_id - new_id) > 2: is_new_q = False
                        else: is_new_q = True 
                except ValueError: is_new_q = True
            else: is_new_q = True

        if is_new_q:
            if current_q:
                questions.append(finalize_question(current_q, questions))
            
            full_text = q_text
            if pending_context:
                full_text = f"{pending_context.strip()}\n{full_text}"
                pending_context = "" 

            current_q = {
                "id": q_id_str,
                "section": current_section,
                "text": full_text, 
                "options": [],
                "sub_items": [],
                "type": "unknown",
                "note": None
            }
            continue

        sub_match = item_pat.match(line_stripped)
        if sub_match and current_q:
            label_char = sub_match.group(1)
            label_num = sub_match.group(2)
            content = sub_match.group(3).strip()
            content = clean_underscores(content)
            
            is_subj_q = is_subjective_question(current_q['text'])

            if label_char:
                is_eng = re.match(r'[a-zA-Z]', label_char)
                if is_eng and is_subj_q:
                    current_q['text'] += f"\n- ({label_char}) {content}"
                elif is_eng and not is_subj_q: 
                    current_q['options'].append({"label": label_char, "text": content})
                else:
                    if is_subj_q: add_sub_item(current_q, f"({label_char}) {content}")
                    else: current_q['options'].append({"label": label_char, "text": content})
            
            elif label_num or (sub_match.group(0).strip().startswith('-') or sub_match.group(0).strip().startswith('•') or sub_match.group(0).strip().startswith('*') or sub_match.group(0).strip().startswith('_')):
                if looks_like_code(content):
                    if current_q['options']: current_q['options'][-1]['text'] += "\n" + line.strip()
                    elif current_q['sub_items']: current_q['sub_items'][-1] += "\n" + line.strip()
                    else: current_q['text'] += "\n" + line.strip()
                elif is_subj_q:
                    prefix = f"{label_num}. " if label_num else "- "
                    add_sub_item(current_q, f"{prefix}{content}")
                else:
                    if current_q['options']: current_q['options'][-1]['text'] += "\n" + line.strip()
                    else: current_q['text'] += "\n" + line.strip()
            continue

        if current_q:
            is_matching = "จับคู่" in current_q['text']
            line_cleaned = clean_underscores(line.strip())
            
            if is_matching and not looks_like_code(line_stripped):
                 add_sub_item(current_q, line_cleaned)
            else:
                join_char = "\n" 
                if current_q['sub_items']: 
                    current_q['sub_items'][-1] += join_char + line.strip()
                elif current_q['options']: 
                    current_q['options'][-1]['text'] += join_char + line.strip()
                else: 
                    current_q['text'] += join_char + line.strip()

    if current_q:
        questions.append(finalize_question(current_q, questions))

    with open(output_filename, 'w', encoding='utf-8') as f:
        json.dump(questions, f, ensure_ascii=False, indent=2)

    print(f"Success! Processed {len(questions)} questions.")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Parse OCR Markdown into structured JSON")
    parser.add_argument("md_path", nargs="?", default="exam_cleaned_output.md", help="Path to input .md (from ocr.py)")
    parser.add_argument("--out", default="exam_data.json", help="Path to output .json")
    args = parser.parse_args()

    try:
        parse_exam_to_json(args.md_path, args.out)
    except Exception as e:
        print(f"[parse_exam.py] ERROR: {e}", file=sys.stderr)
        raise