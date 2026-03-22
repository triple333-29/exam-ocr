import os
import json
import sys
import re
import fitz  # PyMuPDF
from dotenv import load_dotenv
from typhoon_ocr import ocr_document

load_dotenv()

if sys.platform == "win32":
    sys.stdout.reconfigure(encoding='utf-8')

def extract_from_raw_text(text):
    results = []
    
    # 1. ดึงรหัสวิชา (6 หลัก)
    subject_match = re.search(r"(?:รายวิชา|วิชา|Subject|Course)\s*[:\-\s]\s*(\d{6})", text)
    subject_id = subject_match.group(1).strip() if subject_match else "000000"

    # 2. รายชื่อนักศึกษา: เน้นกวาดไทยยาวๆ (60 ตัวอักษร)
    # (6\d{8}) = รหัสนักศึกษา
    # ([ก-๙\.\s]{5,60}) = กวาดทุกอย่างที่เป็นไทย/จุด/เว้นวรรค
    pattern = re.compile(r"(6\d{8})\s+([ก-๙\.\s]{5,60})")
    
    # รายชื่อสาขาวิชาที่มีในคณะ (เพิ่มชื่อสาขาอื่นๆ ลงในนี้ได้เลย)
    major_list = ["คณิตศาสตร์", "วิทยาศาสตร์", "ภาษาอังกฤษ", "คอมพิวเตอร์", "เคมี", "ฟิสิกส์", "ชีววิทยา"]

    lines = text.splitlines()
    for line in lines:
        line = line.strip()
        match = pattern.search(line)
        if match:
            sid = match.group(1)
            raw_full_text = match.group(2).strip()
            
            # ลบตัวเลขลำดับที่ชอบหลุดมาท้ายบรรทัดออกก่อน
            raw_full_text = re.sub(r'\s+\d+$', '', raw_full_text)
            
            clean_name = raw_full_text
            major_found = ""
            
            # --- เทคนิคใหม่: ตัดด้วยชื่อสาขาจริง ---
            for major_name in major_list:
                if major_name in raw_full_text:
                    # ตัดชื่อสาขาออกเพื่อรักษานามสกุล
                    parts = raw_full_text.split(major_name)
                    clean_name = parts[0].strip()
                    major_found = major_name
                    break
            
            # กรณีที่ไม่เจอชื่อสาขาในลิสต์ ให้เช็คว่ามีเว้นวรรคกว้างๆ (4 ช่องขึ้นไป) ไหม
            if not major_found and "    " in clean_name:
                parts = re.split(r'\s{4,}', clean_name)
                clean_name = parts[0].strip()
                major_found = parts[1].strip() if len(parts) > 1 else ""

            results.append({
                "username": sid,
                "full_name": clean_name,
                "major": major_found,
                "subject": subject_id
            })
    return results

def process_file(file_path):
    all_students = []
    try:
        doc = fitz.open(file_path)
        for page in doc:
            # ดึงข้อความแบบเรียงลำดับแถว
            text = page.get_text("text", sort=True)
            found_in_page = extract_from_raw_text(text)
            
            # หากหน้าไหนอ่านไม่ออก ให้ใช้ OCR
            if len(found_in_page) == 0:
                pix = page.get_pixmap(matrix=fitz.Matrix(2, 2))
                temp_img = f"temp_{page.number}.png"
                pix.save(temp_img)
                ocr_text = ocr_document(pdf_or_image_path=temp_img)
                if os.path.exists(temp_img): os.remove(temp_img)
                found_in_page = extract_from_raw_text(ocr_text)
            
            all_students.extend(found_in_page)
        doc.close()

        # ลบข้อมูลซ้ำ
        seen = set()
        final_list = []
        for s in all_students:
            if s['username'] not in seen:
                final_list.append(s)
                seen.add(s['username'])
        
        final_list.sort(key=lambda x: x['username'])
        return final_list
        
    except Exception as e:
        return {"error": str(e)}

if __name__ == "__main__":
    if len(sys.argv) > 1:
        print(json.dumps(process_file(sys.argv[1]), ensure_ascii=False))