import os
import tempfile
import re
import fitz
import argparse
import sys
import time

from PIL import Image, ImageEnhance
from dotenv import load_dotenv
from typhoon_ocr import ocr_document

load_dotenv()
api_key = os.getenv("TYPHOON_API_KEY")
os.environ["TYPHOON_OCR_API_KEY"] = api_key if api_key else ""

# Config & Patterns
HEADER_PATTERN = re.compile(r"517\s*321\s*[-–]?\s*\d+", re.IGNORECASE)
PAGE_FOOTER_PATTERN = re.compile(
    r"^\s*(?:\*\*)?\s*(?:ข้อสอบ(?:บรรยาย|ปรนัย|อัตนัย)?\s*)?\d+\s*\d+\s*[-–]\s*หน้า\s*\d+\s*/\s*\d+\s*(?:\*\*)?\s*$",
    re.IGNORECASE
)
STUDENT_ID_PATTERN = re.compile(r"^\s*รหัส.*$", re.IGNORECASE)
QUESTION_START_PATTERN = re.compile(r"^\s*\d+(\.|\)|\])", re.IGNORECASE) 
OPTION_START_PATTERN = re.compile(r"^\s*(?:______)?\s*(?:\(?\s*[a-zA-Zก-ฮ]\s*[\)\.]|\d+\.)", re.IGNORECASE)

PYTHON_KEYWORDS = [
    "def ", "import ", "from ", "class ", "print(", "# ", 
    "elif ", "pass", "range(", "input("
]
C_FAMILY_KEYWORDS = [
    "#include", "stdio", "int main", "void ", "printf", "scanf", "cout", "cin",
    "std::", "struct ", "public ", "private ", "System.out", "String args", 
    "using namespace", "return 0;"
]
GENERIC_KEYWORDS = [
    "return", "if ", "else", "for ", "while ", 
    "=", "==", "+=", "-=", "*=", "[", "]", "{", "}"
]

#Logic Functions
def clean_answer_space(text: str, is_code: bool = False) -> str:
    if not text: return ""
    text = text.replace("\\_", "_")
    if is_code:
        return text
    text = re.sub(r'[ \t]{3,}', ' ______ ', text)
    text = re.sub(r'(?:_|\.|\s[_\.]){3,}', '______', text)
    
    return text

def normalize_text(text: str) -> str:
    if not text: return ""
    lines = text.splitlines()
    cleaned_lines = []
    
    for line in lines:
        line = line.strip()
        if not line:
            cleaned_lines.append("")
            continue
            
        line = re.sub(r'^\s*(?:O|o|•|☐|➢|®|0|\*|■|♦)\s+', '- ', line)
        line = re.sub(r'^\s*\(?([ก-ฮa-zA-Z])[\)\.]\s+', r'- (\1) ', line)
        line = re.sub(r'^\s*(\d+\.\d+)\)?\s+', r'- \1 ', line)
        cleaned_lines.append(line)
        
    text = "\n".join(cleaned_lines)
    text = re.sub(r'(\n\s*______\s*)+', '\n______', text)
    return text

def check_content_to_skip(text: str) -> bool:
    if not text: return False
    keywords = ["มหาวิทยาลัย", "คณะวิทยาศาสตร์", "ข้อสอบกลางภาค", "ข้อสอบปลายภาค", "สอบวัน", "เวลา", "คะแนน"]
    found_count = sum(1 for k in keywords if k in text)
    has_instruction = "คำสั่ง" in text or "คาสง" in text or "ข้อสอบ" in text
    if found_count >= 2 and has_instruction: return True
    if "กระดาษคำตอบ" in text and ("ทำเครื่องหมาย" in text or "ช่องคำตอบ" in text): return True
    return False

def is_noise_line(line: str) -> bool:
    line = line.strip()
    if not line: return False
    if HEADER_PATTERN.search(line): return True
    if PAGE_FOOTER_PATTERN.match(line): return True
    if STUDENT_ID_PATTERN.match(line): return True
    if re.match(r'^[\d\s/,".]+$', line): return True
    if re.match(r'^"ข้อ.*"คะแนน"', line): return True
    if re.match(r'^[\d\s/]+$', line) and len(line) < 5: return True
    return False

def is_code_line(line: str) -> bool:
    stripped = line.strip()
    if not stripped: return False
    if QUESTION_START_PATTERN.match(stripped): return False
    if OPTION_START_PATTERN.match(stripped): return False
    if stripped.startswith("- "): return False
    all_keywords = PYTHON_KEYWORDS + C_FAMILY_KEYWORDS + GENERIC_KEYWORDS
    if any(k in stripped for k in all_keywords): return True
    if re.search(r'(\{|\}|;)$', stripped): return True 
    return False

def detect_language(code_lines):
    text = "\n".join(code_lines)
    c_score = sum(1 for k in C_FAMILY_KEYWORDS if k in text) + text.count(';') + text.count('{')
    py_score = sum(1 for k in PYTHON_KEYWORDS if k in text) + text.count('def ') + text.count(':')
    
    if c_score > py_score: return "c"
    if py_score > 0: return "python"
    return "" 

def apply_code_formatting(full_text: str) -> str:
    
    lines = full_text.splitlines()
    output = []
    code_buffer = [] 
    
    for line in lines:
        if is_noise_line(line): continue
        
        is_code = is_code_line(line)
        
        if is_code:
            code_buffer.append(line)
        else:
            if code_buffer:
                lang = detect_language(code_buffer)
                output.append(f"\n```{lang}")
                output.extend(code_buffer)
                output.append("```\n")
                code_buffer = []
            
            cleaned_line = clean_answer_space(line)
            output.append(cleaned_line)
            
    if code_buffer:
        lang = detect_language(code_buffer)
        output.append(f"\n```{lang}")
        output.extend(code_buffer)
        output.append("```")
        
    return "\n".join(output)

def process_page(doc, page_num):
    # เริ่มต้นจับเวลา
    start_time = time.time()
    
    page = doc.load_page(page_num)
    
    # 1. เช็ค Metadata
    print(f"   [1/5] Checking Metadata...", end=" ", flush=True)
    pre_text = page.get_text("text")
    if check_content_to_skip(pre_text): 
        print("Skipped (Instruction/Cover page).")
        return None
    print("OK.")

    mat = fitz.Matrix(3, 3)
    pix = page.get_pixmap(matrix=mat, alpha=False)
    fd, temp_path = tempfile.mkstemp(suffix=".png")
    os.close(fd) 

    try:
        # 2. แปลง PDF เป็นรูป
        print(f"   [2/5] Converting PDF to Image...", end=" ", flush=True)
        pix.save(temp_path)
        print("Done.")

        # 3. ปรับแต่งรูปภาพ
        print(f"   [3/5] Enhancing Image Contrast...", end=" ", flush=True)
        img = Image.open(temp_path).convert("RGB")
        img = ImageEnhance.Contrast(img).enhance(2.0)
        img.save(temp_path)
        print("Done.")

        # 4. เรียก Typhoon OCR API
        print(f"   [4/5] Sending to Typhoon API (Wait)...", end=" ", flush=True)
        raw_text = ocr_document(pdf_or_image_path=temp_path)
        print("Received.")
        
        if check_content_to_skip(raw_text): 
            print("   -> Content Check: Skipped.")
            return None
        
        # 5. จัดรูปแบบข้อความ
        print(f"   [5/5] Formatting Text...", end=" ", flush=True)
        formatted_text = apply_code_formatting(raw_text)
        
        elapsed = time.time() - start_time
        print(f"Done! ({elapsed:.2f}s)")
        
        return formatted_text
        
    except Exception as e:
        print(f"\n   [ERROR] on page {page_num}: {e}")
        return ""
    finally:
        try:
            if os.path.exists(temp_path): os.remove(temp_path)
        except OSError: pass

def main(pdf_path: str, output_md: str = "exam_cleaned_output.md") -> str:
    if not pdf_path or not os.path.exists(pdf_path):
        raise FileNotFoundError(f"File not found: {pdf_path}")

    if not pdf_path.lower().endswith(".pdf"):
        raise ValueError("Input file must be a .pdf")

    doc = fitz.open(pdf_path)
    all_content = []
    print(f"==========================================")
    print(f"Processing PDF: {pdf_path}")
    print(f"Total Pages: {doc.page_count}")
    print(f"==========================================\n")

    for i in range(doc.page_count):
        print(f"PAGE {i+1}:")
        page_content = process_page(doc, i)
        
        if page_content:
            all_content.append(f"## Page {i+1}\n\n{page_content}\n\n---\n")
        
        print("-" * 40) # ขีดเส้นคั่นแต่ละหน้า

    os.makedirs(os.path.dirname(output_md) or ".", exist_ok=True)
    with open(output_md, "w", encoding="utf-8") as f:
        f.write("\n".join(all_content))

    print(f"\n All Done! Saved to: {output_md}")
    return output_md


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="OCR PDF to Markdown")
    parser.add_argument("pdf_path", help="Path to input PDF")
    parser.add_argument("--out", default="exam_cleaned_output.md", help="Path to output .md")
    args = parser.parse_args()

    try:
        main(args.pdf_path, args.out)
    except Exception as e:
        print(f"[ocr.py] ERROR: {e}")
        raise