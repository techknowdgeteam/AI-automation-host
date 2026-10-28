import pyautogui
from PIL import Image
import os
import glob
from datetime import datetime
import subprocess
import json
import win32gui
import win32con
import win32process
import win32api
import win32process
import psutil
import time
import re
import difflib
import pyperclip
import keyboard 
import tkinter as tk
import threading
import zipfile
import csv
import cv2
import numpy as np
from PIL import Image, ImageDraw, ImageFont
import os
from concurrent.futures import ThreadPoolExecutor, as_completed
from moviepy import ImageClip
import os
import re
import json
import shutil
import os
import json
import warnings
from datetime import datetime
from moviepy import ImageClip, concatenate_videoclips, AudioFileClip, VideoClip, CompositeVideoClip
from moviepy import ImageClip, concatenate_videoclips, AudioFileClip
from PIL import Image, ImageDraw, ImageFont
import numpy as np
import random

# Suppress warnings
warnings.filterwarnings('ignore')

# Fix protobuf compatibility
os.environ['PROTOCOL_BUFFERS_PYTHON_IMPLEMENTATION'] = 'python'

# Import the library
from chrome_lens_py import LensAPI




# Configure Paths
#pytesseract.pytesseract.tesseract_cmd = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
#os.environ["TESSDATA_PREFIX"] = r"C:\xampp\htdocs\AI automation\scenIQ\pytesseract\tessdata"
#tessdata_path = r"C:\xampp\htdocs\AI automation\scenIQ\pytesseract\tessdata\eng.traineddata"
edge_path = r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
SCREEN_IMAGE = r"C:\xampp\htdocs\AI automation\scenIQ\input_images\screen.png"
TEXT_TARGET = r"C:\xampp\htdocs\AI automation\scenIQ\text_target.json"
PANEL_PATH = r"C:\xampp\htdocs\AI automation\scenIQ\panel.json"
SCREEN_TEXT_CONTENT = r"C:\xampp\htdocs\AI automation\scenIQ\screen_content.json"
INPUT_IMAGES = r"C:\xampp\htdocs\AI automation\scenIQ\input_images"
IMAGES_PATH = r"C:\xampp\htdocs\AI automation\scenIQ\project"
PHPSQLURL = "https://fhdrikxsirudr.fwh.is/phpmyadmintemplate.php"
GEMINI_URL = "https://gemini.google.com/app"
GOOGLE_FLOW_URL = "https://flow.google.com/#overview"
GUI_IMAGES = r"C:\xampp\htdocs\AI automation\scenIQ\gui_images"
PANEL_PATH = r"C:\xampp\htdocs\AI automation\scenIQ\panel.json"
READY_SCRIPT = r"C:\xampp\htdocs\AI automation\scenIQ\ready_script.json"
GUI_REGION_SAVER = r"C:\xampp\htdocs\AI automation\scenIQ\gui.json"

class AutomationHUD:
    def __init__(self):
        self.root = None
        self.label_status = None
        self.visible = True
        self.current_text = "Initializing..."
        self.status_history = []
        self.current_status_type = "initializing"
        self.thread = threading.Thread(target=self._run_hud, daemon=True)
        self.thread.start()

    def _run_hud(self):
        self.root = tk.Tk()
        self.root.title("Automation HUD")
        
        # Frameless, always on top, transparent background
        self.root.overrideredirect(True)
        self.root.attributes("-topmost", True)
        self.root.attributes("-transparentcolor", "black")
        self.root.config(bg="black")
        
        # Make window larger to display full text
        sw = self.root.winfo_screenwidth()
        sh = self.root.winfo_screenheight()
        w, h = 600, 120  # Increased height for text visibility
        x = (sw // 2) - (w // 2)
        y = (sh // 2) - (h // 2) - 100
        self.root.geometry(f"{w}x{h}+{x}+{y}")
        
        # Main container with black background
        container = tk.Frame(self.root, bg="black")
        container.pack(fill="both", expand=True, padx=20, pady=20)
        
        # Status message with large, bold font centered
        self.label_status = tk.Label(container, text="🚀 Initializing...", 
                                   font=("Segoe UI", 10, "bold"), 
                                   fg="#00FFCC", bg="black",
                                   wraplength=560, justify="center")
        self.label_status.pack(expand=True, fill="both")
        
        # Apply Windows styling hooks for click-through
        try:
            hwnd = win32gui.GetParent(self.root.winfo_id())
            ex_style = win32gui.GetWindowLong(hwnd, win32con.GWL_EXSTYLE)
            win32gui.SetWindowLong(hwnd, win32con.GWL_EXSTYLE, 
                                 ex_style | win32con.WS_EX_LAYERED | win32con.WS_EX_TRANSPARENT)
        except:
            pass  # Fallback if Windows API fails
        
        self.root.mainloop()

    def print(self, text, status_type="processing"):
        """Update status text"""
        self.current_text = text
        self.current_status_type = status_type
        
        # Store history
        self.status_history.append((time.time(), text))
        if len(self.status_history) > 20:
            self.status_history.pop(0)
        
        # Update UI in thread-safe manner
        if self.root and self.label_status:
            # Color mapping for different status types
            color_map = {
                "searching": "#00CCFF",
                "scanning": "#00FF88",
                "processing": "#00FFCC",
                "success": "#00FF88",
                "warning": "#FFAA00",
                "waiting": "#8888FF",
                "clicking": "#FF66CC",
                "typing": "#66CCFF",
                "navigating": "#FF8844",
                "error": "#FF4466",
                "initializing": "#00FFCC",
                "booting": "#00CCFF",
                "connecting": "#66CCFF",
                "loading": "#00FF88",
                "verifying": "#00FFCC",
                "complete": "#FF66CC"
            }
            color = color_map.get(status_type, "white")
            self.root.after(0, lambda: self.label_status.config(text=text, fg=color))
        
        # Log with timestamp
        timestamp = time.strftime("%H:%M:%S")
        # Extract emoji from text if present
        emoji_pattern = re.compile(r'[\U0001F600-\U0001F64F\U0001F300-\U0001F5FF\U0001F680-\U0001F6FF\U0001F700-\U0001F77F\U0001F780-\U0001F7FF\U0001F800-\U0001F8FF\U0001F900-\U0001F9FF\U0001FA00-\U0001FA6F\U0001FA70-\U0001FAFF\U00002702-\U000027B0\U000024C2-\U0001F251]+', flags=re.UNICODE)
        emojis = emoji_pattern.findall(text)
        icon = emojis[0] if emojis else "📊"
        clean_text = emoji_pattern.sub('', text).strip()
        print(f"{icon} [HUD @ {timestamp}] {clean_text}")

    def hide(self):
        """Hide the HUD (kept for compatibility but not used)"""
        self.visible = False

    def show(self):
        """Show the HUD (kept for compatibility)"""
        self.visible = True

    def show_summary(self, final_status="✅ Operation Complete"):
        """Display final operation summary with flash effect"""
        if self.root:
            self.print(final_status, "complete")
            # Flash effect for completion
            for _ in range(3):
                if self.root:
                    self.root.attributes("-alpha", 0.7)
                    time.sleep(0.1)
                    self.root.attributes("-alpha", 1.0)
                    time.sleep(0.1)

    def cleanup(self):
        """Clean up resources"""
        if self.root:
            self.root.quit()
            self.root.destroy()
hud = AutomationHUD()

def fetch_settings():
    """
    Launches/uses Microsoft Edge for phpMyAdmin operations.
    Gets settings from sceneiq_config and saves to PANEL_PATH.
    Uses clipboard-based communication only.
    Uses PHPSQLURL as the phpMyAdmin URL.
    Creates default files if they don't exist or are invalid.
    Window remains open after completion.
    Automatically cleans and repairs JSON data.
    Removes outer array wrapper.

    Executes only when currently_running is None/"none"/empty or "fetch_settings()".
    Sets currently_running to "fetch_settings()" on start.
    On full success sets currently_running to "fetch_settings_successful".
    """
    import os
    import json
    import time
    import shutil
    import subprocess
    import traceback
    import re

    try:
        import pyautogui
        import pyperclip
        import win32api
        import win32con
        import win32gui
        import win32process
        import psutil
    except ImportError as e:
        print(f"❌ [FETCH] Missing dependency: {e}")
        return False

    pyautogui.PAUSE = 0.0

    # Termination flag
    terminate_automation = False
    MAX_OPERATION_RETRIES = 5

    def check_for_termination():
        if terminate_automation:
            raise KeyboardInterrupt("User forced exit via shortcut key.")

    def _load_panel():
        if not os.path.exists(PANEL_PATH):
            return {}
        try:
            with open(PANEL_PATH, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception:
            return {}

    def _save_panel(data):
        try:
            os.makedirs(os.path.dirname(PANEL_PATH) or '.', exist_ok=True)
            with open(PANEL_PATH, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2, ensure_ascii=False)
            return True
        except Exception as e:
            print(f"❌ [STATUS] Failed to save panel: {e}")
            return False

    def _get_currently_running():
        data = _load_panel()
        val = data.get("currently_running")
        if val is None:
            return None
        val = str(val).strip()
        if val.lower() in ("", "none", "null"):
            return None
        return val

    def _set_currently_running(value, status=None, operation_status=None):
        data = _load_panel()
        data["currently_running"] = value
        if status is not None:
            data["status"] = status
        if operation_status is not None:
            data["operation_status"] = operation_status
        return _save_panel(data)

    # ----- Gate: only run when allowed -----
    current = _get_currently_running()
    allowed = (current is None) or (current == "fetch_settings()")
    if not allowed:
        print(f"⏭️ [FETCH] Skipping – currently_running is '{current}' (expected None or 'fetch_settings()')")
        return False

    # Claim the slot
    _set_currently_running("fetch_settings()", status="pending",
                           operation_status="Running fetch_settings()")
    print("🔒 [FETCH] Claimed currently_running = 'fetch_settings()'")

    # -----------------------------------------------------------------
    # Helper functions (self-contained)
    # -----------------------------------------------------------------
    def ensure_panel_path_exists():
        if os.path.exists(PANEL_PATH):
            try:
                with open(PANEL_PATH, 'r', encoding='utf-8') as file:
                    content = file.read().strip()
                    if not content:
                        return False
                    json.loads(content)
                return True
            except Exception:
                try:
                    os.remove(PANEL_PATH)
                except Exception:
                    pass

        default_config = {
            "url": PHPSQLURL,
            "author": "Brilliance",
            "engine": "csv",
            "page": "none",
            "group": "include",
            "processjpgfrom": "freshjpgs",
            "status": "pending",
            "currently_running": "fetch_settings()",
            "operation_status": "Initializing"
        }
        try:
            os.makedirs(os.path.dirname(PANEL_PATH) or '.', exist_ok=True)
            with open(PANEL_PATH, 'w', encoding='utf-8') as file:
                json.dump(default_config, file, indent=2, ensure_ascii=False)
            return True
        except Exception:
            return False

    def clean_and_repair_json(content):
        try:
            return json.loads(content)
        except Exception:
            pass
        try:
            cleaned = content.strip()
            if cleaned.startswith('"') and cleaned.endswith('"'):
                cleaned = cleaned[1:-1]
                cleaned = cleaned.replace('\\"', '"').replace('\\\\', '\\')
            try:
                return json.loads(cleaned)
            except Exception:
                pass
            try:
                parsed = json.loads(cleaned)
                if isinstance(parsed, list) and len(parsed) == 1 and isinstance(parsed[0], str):
                    inner = parsed[0]
                    if inner.startswith('{') or inner.startswith('['):
                        return json.loads(inner)
            except Exception:
                pass
            cleaned = cleaned.replace('\\"', '"').replace('\\\\', '\\')
            cleaned = cleaned.replace('\\n', ' ').replace('\\r', ' ').replace('\\t', ' ')
            cleaned = cleaned.replace('"true"', 'true').replace('"false"', 'false')
            cleaned = cleaned.replace("'true'", 'true').replace("'false'", 'false')
            cleaned = cleaned.replace('"null"', 'null').replace("'null'", 'null')
            cleaned = re.sub(r',\s*}', '}', cleaned)
            cleaned = re.sub(r',\s*]', ']', cleaned)
            json_match = re.search(r'(\{.*\}|\[.*\])', cleaned, re.DOTALL)
            if json_match:
                cleaned = json_match.group(1)
            try:
                return json.loads(cleaned)
            except Exception:
                pass
            return {}
        except Exception as e:
            print(f"⚠️ [CLEAN] Error cleaning JSON: {e}")
            return {}

    def clean_json_data(data):
        if isinstance(data, dict):
            return {k: clean_json_data(v) for k, v in data.items()}
        elif isinstance(data, list):
            return [clean_json_data(item) for item in data]
        elif isinstance(data, str):
            stripped = data.strip()
            if stripped.startswith('{') and stripped.endswith('}'):
                try:
                    return clean_json_data(json.loads(stripped))
                except Exception:
                    pass
            elif stripped.startswith('[') and stripped.endswith(']'):
                try:
                    return clean_json_data(json.loads(stripped))
                except Exception:
                    pass
            elif stripped.lower() == 'true':
                return True
            elif stripped.lower() == 'false':
                return False
            elif stripped.lower() == 'null':
                return None
            return data
        return data

    def remove_outer_array_wrapper(data):
        if isinstance(data, list):
            if len(data) == 1:
                return data[0]
            return data
        return data

    def repair_json_file(file_path):
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                content = f.read()
            cleaned_data = clean_and_repair_json(content)
            cleaned_data = remove_outer_array_wrapper(cleaned_data)
            final_data = clean_json_data(cleaned_data)
            # Preserve status fields if they exist
            existing = _load_panel()
            if isinstance(final_data, dict):
                for key in ("status", "currently_running", "operation_status"):
                    if key in existing and key not in final_data:
                        final_data[key] = existing[key]
            with open(file_path, 'w', encoding='utf-8') as f:
                json.dump(final_data, f, indent=2, ensure_ascii=False)
            return True, final_data
        except Exception as e:
            print(f"❌ [REPAIR] Failed to repair JSON: {e}")
            return False, None

    def get_current_monitor():
        try:
            cursor_pos = win32api.GetCursorPos()
            monitor_info = win32api.GetMonitorInfo(win32api.MonitorFromPoint(cursor_pos))
            return monitor_info['Monitor']
        except Exception:
            return (0, 0, win32api.GetSystemMetrics(win32con.SM_CXSCREEN),
                    win32api.GetSystemMetrics(win32con.SM_CYSCREEN))

    def get_edge_window_on_monitor(monitor_bounds):
        monitor_left, monitor_top, monitor_right, monitor_bottom = monitor_bounds
        edge_windows = []
        edge_process_names = ["msedge.exe"]

        def enum_windows_callback(hwnd, windows):
            if win32gui.IsWindowVisible(hwnd):
                try:
                    _, pid = win32process.GetWindowThreadProcessId(hwnd)
                    process = psutil.Process(pid)
                    if process.name().lower() in edge_process_names:
                        rect = win32gui.GetWindowRect(hwnd)
                        left, top, right, bottom = rect
                        width, height = right - left, bottom - top
                        if width > 200 and height > 200:
                            window_center_x = (left + right) / 2
                            window_center_y = (top + bottom) / 2
                            is_on_current_monitor = (
                                monitor_left <= window_center_x <= monitor_right and
                                monitor_top <= window_center_y <= monitor_bottom
                            )
                            if is_on_current_monitor:
                                windows.append({'hwnd': hwnd, 'width': width, 'height': height})
                except Exception:
                    pass
            return True

        win32gui.EnumWindows(enum_windows_callback, edge_windows)
        edge_windows.sort(key=lambda w: w['width'] * w['height'], reverse=True)
        return edge_windows

    def ensure_edge_window_ready():
        check_for_termination()
        current_monitor = get_current_monitor()
        print(f"🖥️ [WATCHDOG] Monitor bounds: {current_monitor}")
        edge_windows = get_edge_window_on_monitor(current_monitor)

        if edge_windows:
            hwnd = edge_windows[0]['hwnd']
            print(f"🪟 [WATCHDOG] Found existing Edge window handle: {hwnd}")
            try:
                if win32gui.IsIconic(hwnd):
                    win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                    time.sleep(0.3)
                win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                time.sleep(0.5)
                try:
                    win32gui.SetForegroundWindow(hwnd)
                    time.sleep(0.2)
                except Exception:
                    try:
                        pyautogui.hotkey('alt', 'tab')
                        time.sleep(0.3)
                    except Exception:
                        pass
                return hwnd
            except Exception:
                return hwnd

        subprocess.Popen([edge_path, "about:blank"])
        for attempt in range(20):
            check_for_termination()
            time.sleep(0.5)
            edge_windows = get_edge_window_on_monitor(current_monitor)
            if edge_windows:
                hwnd = edge_windows[0]['hwnd']
                try:
                    if win32gui.IsIconic(hwnd):
                        win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                        time.sleep(0.3)
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.5)
                    try:
                        win32gui.SetForegroundWindow(hwnd)
                        time.sleep(0.2)
                    except Exception:
                        pass
                    return hwnd
                except Exception:
                    continue
        raise RuntimeError("Failed to get or launch Edge window")

    def enforce_window_focus(hwnd):
        check_for_termination()
        try:
            if not win32gui.IsWindow(hwnd):
                return ensure_edge_window_ready()
            if win32gui.IsIconic(hwnd):
                win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                time.sleep(0.3)
            current_foreground = win32gui.GetForegroundWindow()
            if current_foreground != hwnd:
                try:
                    win32gui.SetForegroundWindow(hwnd)
                    time.sleep(0.15)
                except Exception:
                    try:
                        pyautogui.hotkey('alt', 'tab')
                        time.sleep(0.3)
                    except Exception:
                        pass
            try:
                placement = win32gui.GetWindowPlacement(hwnd)
                if placement[1] != win32con.SW_SHOWMAXIMIZED:
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.3)
            except Exception:
                try:
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.3)
                except Exception:
                    pass
            return hwnd
        except Exception:
            return hwnd

    def ensure_window_ready_and_focused():
        check_for_termination()
        hwnd = ensure_edge_window_ready()
        return enforce_window_focus(hwnd)

    def fast_paste_url(hwnd, url, retry_count=0):
        check_for_termination()
        print(f"📋 Pasting URL: {url}")
        pyperclip.copy(url)
        try:
            hwnd = enforce_window_focus(hwnd)
            pyautogui.hotkey('ctrl', 'l')
            time.sleep(0.1)
            hwnd = enforce_window_focus(hwnd)
            pyautogui.hotkey('ctrl', 'v')
            pyautogui.press('enter')
            return True, hwnd
        except Exception:
            if retry_count < 3:
                time.sleep(0.5)
                hwnd = ensure_window_ready_and_focused()
                return fast_paste_url(hwnd, url, retry_count + 1)
            return False, hwnd

    def wait_for_clipboard_content(expected_contains=None, timeout=60, check_interval=0.5):
        check_for_termination()
        start_time = time.time()
        while time.time() - start_time < timeout:
            check_for_termination()
            try:
                current_content = pyperclip.paste()
                if current_content and current_content.strip():
                    if expected_contains:
                        if expected_contains in current_content:
                            return current_content
                    else:
                        return current_content
            except Exception:
                pass
            time.sleep(check_interval)
        return None

    def wait_for_enter_confirmation(timeout=5, check_interval=0.3):
        start_time = time.time()
        while time.time() - start_time < timeout:
            check_for_termination()
            try:
                current_content = pyperclip.paste()
                if current_content and "enter button activated" in current_content:
                    return True
            except Exception:
                pass
            time.sleep(check_interval)
        return False

    def create_backup_file(file_path):
        backup_path = file_path + ".backup"
        if os.path.exists(file_path):
            shutil.copy2(file_path, backup_path)
            return backup_path
        return None

    def restore_from_backup(file_path):
        backup_path = file_path + ".backup"
        if os.path.exists(backup_path):
            try:
                if os.path.exists(file_path):
                    os.remove(file_path)
                shutil.copy2(backup_path, file_path)
                os.remove(backup_path)
                return True
            except Exception:
                return False
        return False

    def wait_for_clipboard_data_with_retry(max_retries=15, retry_delay=1.0, min_content_length=10, hwnd=None):
        print(f"⏳ [CLIPBOARD] Waiting for data with {max_retries} retries...")
        previous_content = None
        enter_confirmation_seen = False
        for attempt in range(max_retries):
            check_for_termination()
            try:
                current_content = pyperclip.paste()
                if current_content and current_content.strip():
                    content_length = len(current_content.strip())
                    if "enter button activated" in current_content:
                        if not enter_confirmation_seen:
                            print("ℹ️ [CLIPBOARD] Enter confirmation received, waiting for actual data...")
                            enter_confirmation_seen = True
                        previous_content = current_content
                        time.sleep(retry_delay)
                        continue
                    if current_content != previous_content:
                        print(f"📋 [CLIPBOARD] Attempt {attempt + 1}/{max_retries}: New content found ({content_length} chars)")
                        if content_length > min_content_length:
                            return current_content
                        else:
                            print(f"⚠️ [CLIPBOARD] Content too short ({content_length} chars), waiting...")
                    else:
                        print(f"⏳ [CLIPBOARD] Attempt {attempt + 1}/{max_retries}: No new content")
                else:
                    print(f"⏳ [CLIPBOARD] Attempt {attempt + 1}/{max_retries}: Empty clipboard")
                previous_content = current_content
            except Exception as e:
                print(f"⚠️ [CLIPBOARD] Error reading: {e}")
            if attempt > 0 and attempt % 5 == 0 and hwnd:
                try:
                    hwnd = enforce_window_focus(hwnd)
                except Exception:
                    pass
            if attempt < max_retries - 1:
                time.sleep(retry_delay)
        return None

    def clean_clipboard_data(raw_data):
        try:
            data = raw_data.strip()
            if data.startswith('"') and data.endswith('"'):
                try:
                    parsed = json.loads(data)
                    if isinstance(parsed, str):
                        data = parsed
                except Exception:
                    data = data[1:-1]
                    data = data.replace('\\"', '"').replace('\\\\', '\\')
            json_match = re.search(r'(\{.*\}|\[.*\])', data, re.DOTALL)
            if json_match:
                data = json_match.group(1)
            bracket_count = 0
            in_string = False
            escape_next = False
            end_pos = -1
            for i, char in enumerate(data):
                if escape_next:
                    escape_next = False
                    continue
                if char == '\\':
                    escape_next = True
                    continue
                if char == '"' and not escape_next:
                    in_string = not in_string
                    continue
                if not in_string:
                    if char in '{[':
                        bracket_count += 1
                    elif char in '}]':
                        bracket_count -= 1
                        if bracket_count == 0:
                            end_pos = i + 1
                            break
            if end_pos > 0:
                data = data[:end_pos]
            data = re.sub(r',\s*}', '}', data)
            data = re.sub(r',\s*]', ']', data)
            data = data.replace('"true"', 'true').replace('"false"', 'false')
            data = data.replace("'true'", 'true').replace("'false'", 'false')
            data = data.replace('"null"', 'null').replace("'null'", 'null')
            return data
        except Exception as e:
            print(f"⚠️ [CLEAN] Error cleaning clipboard data: {e}")
            return raw_data

    def overwrite_file_with_content(file_path, content):
        try:
            cleaned_content = clean_clipboard_data(content)
            try:
                parsed_content = json.loads(cleaned_content)
                parsed_content = remove_outer_array_wrapper(parsed_content)
                parsed_content = clean_json_data(parsed_content)
                # Preserve status fields
                existing = _load_panel()
                if isinstance(parsed_content, dict):
                    for key in ("status", "currently_running", "operation_status"):
                        if key in existing:
                            parsed_content[key] = existing[key]
                content = json.dumps(parsed_content, indent=2, ensure_ascii=False)
            except json.JSONDecodeError as e:
                print(f"⚠️ [PARSE] Initial parse failed: {e}, trying deeper cleaning...")
                try:
                    parsed_content = clean_and_repair_json(cleaned_content)
                    parsed_content = remove_outer_array_wrapper(parsed_content)
                    parsed_content = clean_json_data(parsed_content)
                    existing = _load_panel()
                    if isinstance(parsed_content, dict):
                        for key in ("status", "currently_running", "operation_status"):
                            if key in existing:
                                parsed_content[key] = existing[key]
                    content = json.dumps(parsed_content, indent=2, ensure_ascii=False)
                except Exception as e2:
                    print(f"⚠️ [PARSE] Deep cleaning failed: {e2}")
                    content = cleaned_content
            with open(file_path, 'w', encoding='utf-8') as f:
                f.write(content)
            return True
        except Exception as e:
            print(f"❌ [SAVE] Error saving file: {e}")
            return False

    def execute_sql_query_and_save(hwnd, sql_query, file_path, operation_description, retry_count=0):
        try:
            print(f"\n{'='*60}")
            print(f"🚀 [OPERATION] {operation_description} (Attempt {retry_count + 1}/{MAX_OPERATION_RETRIES})")
            print(f"📝 [SQL] {sql_query}")
            print(f"💾 [OUTPUT] {file_path}")
            print(f"{'='*60}")

            print("⌨️ [STEP 1] Ensuring window focus and maximized state...")
            hwnd = enforce_window_focus(hwnd)
            time.sleep(0.2)

            print("⌨️ [STEP 2] Pressing Tab to focus textarea...")
            hwnd = enforce_window_focus(hwnd)
            pyautogui.press('tab')
            time.sleep(0.5)

            print("⌨️ [STEP 3] Ctrl+A to select all text...")
            hwnd = enforce_window_focus(hwnd)
            pyautogui.hotkey('ctrl', 'a')
            time.sleep(0.2)

            print("⌨️ [STEP 4] Deleting text...")
            pyautogui.press('delete')
            time.sleep(0.3)

            print(f"⌨️ [STEP 5] Typing SQL query: {sql_query}")
            hwnd = enforce_window_focus(hwnd)
            pyperclip.copy(sql_query)
            pyautogui.hotkey('ctrl', 'v')
            time.sleep(0.3)

            print("⌨️ [STEP 6] Pressing Enter to execute query...")
            hwnd = enforce_window_focus(hwnd)
            pyautogui.press('enter')

            enter_confirmed = wait_for_enter_confirmation(timeout=5)
            if enter_confirmed:
                print("✅ [STEP 6] Enter confirmed")
            else:
                print("⚠️ [STEP 6] Enter not confirmed, but proceeding")

            print("⏳ [STEP 7] Waiting for query execution...")
            time.sleep(2.0)

            print("⌨️ [STEP 8] Pressing Ctrl+M to copy results...")
            hwnd = enforce_window_focus(hwnd)
            pyautogui.hotkey('ctrl', 'm')
            time.sleep(1.0)

            print("⏳ [STEP 9] Waiting for clipboard data...")
            backup_created = create_backup_file(file_path)

            final_result = wait_for_clipboard_data_with_retry(
                max_retries=20,
                retry_delay=1.0,
                min_content_length=20,
                hwnd=hwnd
            )

            if not final_result:
                error_msg = (f"SQL query '{sql_query}' executed but no data was returned "
                             f"or the clipboard was empty after 20 retry attempts.")
                print(f"❌ {error_msg}")
                if retry_count < MAX_OPERATION_RETRIES - 1:
                    print("🔄 [RETRY] Operation failed, retrying in 2 seconds...")
                    time.sleep(2)
                    hwnd = enforce_window_focus(hwnd)
                    return execute_sql_query_and_save(
                        hwnd, sql_query, file_path, operation_description, retry_count + 1
                    )
                if backup_created and os.path.exists(file_path + ".backup"):
                    restore_from_backup(file_path)
                return False, error_msg

            print(f"✅ [STEP 9] Data received successfully: {len(final_result)} characters")
            print(f"💾 [STEP 10] Overwriting file {file_path} with data...")
            os.makedirs(os.path.dirname(file_path) or '.', exist_ok=True)

            print("🧹 [STEP 11] Cleaning and repairing JSON data...")
            if overwrite_file_with_content(file_path, final_result):
                print("✅ [STEP 11] Data cleaned and saved successfully!")
                backup_path = file_path + ".backup"
                if os.path.exists(backup_path):
                    os.remove(backup_path)
                return True, None
            else:
                error_msg = f"Failed to clean and save data to {file_path}."
                print(f"❌ {error_msg}")
                if retry_count < MAX_OPERATION_RETRIES - 1:
                    print("🔄 [RETRY] File save failed, retrying in 2 seconds...")
                    time.sleep(2)
                    hwnd = enforce_window_focus(hwnd)
                    return execute_sql_query_and_save(
                        hwnd, sql_query, file_path, operation_description, retry_count + 1
                    )
                if backup_created and os.path.exists(file_path + ".backup"):
                    restore_from_backup(file_path)
                return False, error_msg

        except Exception as e:
            error_msg = f"Unexpected error during SQL execution: {str(e)}"
            print(f"❌ {error_msg}")
            traceback.print_exc()
            if retry_count < MAX_OPERATION_RETRIES - 1:
                print("🔄 [RETRY] Exception occurred, retrying in 2 seconds...")
                time.sleep(2)
                try:
                    hwnd = ensure_window_ready_and_focused()
                    return execute_sql_query_and_save(
                        hwnd, sql_query, file_path, operation_description, retry_count + 1
                    )
                except Exception:
                    pass
            try:
                backup_path = file_path + ".backup"
                if os.path.exists(backup_path):
                    restore_from_backup(file_path)
            except Exception:
                pass
            return False, error_msg

    # ============================================================
    # MAIN EXECUTION
    # ============================================================
    try:
        errors_encountered = []
        warnings_encountered = []

        if not ensure_panel_path_exists():
            error_msg = "The configuration file could not be created or validated. Please check file permissions and disk space."
            print(f"❌ {error_msg}")
            errors_encountered.append(error_msg)
            _set_currently_running("fetch_settings()", status="error",
                                   operation_status=f"❌ {error_msg}")
            return False

        try:
            with open(PANEL_PATH, 'r', encoding='utf-8') as file:
                panel_data = json.load(file)
        except json.JSONDecodeError as e:
            error_msg = f"The configuration file contains invalid JSON: {str(e)}. Please check the file format."
            print(f"❌ {error_msg}")
            errors_encountered.append(error_msg)
            _set_currently_running("fetch_settings()", status="error",
                                   operation_status=f"❌ {error_msg}")
            return False
        except Exception as e:
            error_msg = f"Failed to read the configuration file: {str(e)}. Check file permissions."
            print(f"❌ {error_msg}")
            errors_encountered.append(error_msg)
            _set_currently_running("fetch_settings()", status="error",
                                   operation_status=f"❌ {error_msg}")
            return False

        phpmyadmin_url = PHPSQLURL
        print(f"🔍 [PHPMYADMIN] Using PHPSQLURL: {phpmyadmin_url}")

        try:
            hwnd = ensure_window_ready_and_focused()
        except Exception as e:
            error_msg = f"Failed to launch or focus Microsoft Edge: {str(e)}. Check if Edge is installed and accessible."
            print(f"❌ {error_msg}")
            errors_encountered.append(error_msg)
            _set_currently_running("fetch_settings()", status="error",
                                   operation_status=f"❌ {error_msg}")
            return False

        success, hwnd = fast_paste_url(hwnd, phpmyadmin_url)
        if not success:
            error_msg = f"Failed to navigate to '{phpmyadmin_url}'. The browser may have issues loading the page."
            print(f"❌ {error_msg}")
            errors_encountered.append(error_msg)
            _set_currently_running("fetch_settings()", status="error",
                                   operation_status=f"❌ {error_msg}")
            return False

        print("⏳ [NAVIGATION] Waiting for page to load...")
        page_ready = None
        for attempt in range(MAX_OPERATION_RETRIES):
            hwnd = enforce_window_focus(hwnd)
            if attempt > 0:
                print(f"🔄 [NAVIGATION] Reloading page (attempt {attempt + 1})...")
                pyautogui.hotkey('ctrl', 'r')
                time.sleep(2)
                hwnd = enforce_window_focus(hwnd)
            page_ready = wait_for_clipboard_content(
                expected_contains="page is ready",
                timeout=15 if attempt == 0 else 10,
                check_interval=0.5
            )
            if page_ready:
                print(f"✅ [NAVIGATION] Page is ready (attempt {attempt + 1})")
                break
            else:
                print(f"⚠️ [NAVIGATION] Page not ready (attempt {attempt + 1})")
                time.sleep(1)

        if not page_ready:
            error_msg = (f"The phpMyAdmin page at '{phpmyadmin_url}' failed to load after "
                         f"{MAX_OPERATION_RETRIES} attempts. Check if the URL is accessible and the server is running.")
            print(f"❌ {error_msg}")
            errors_encountered.append(error_msg)
            _set_currently_running("fetch_settings()", status="error",
                                   operation_status=f"❌ {error_msg}")
            return False

        print("✅ [NAVIGATION] Page is ready")
        hwnd = enforce_window_focus(hwnd)

        # OPERATION: Get settings from sceneiq_config
        sql_query = "select settings from sceneiq_config"
        success, error_msg = execute_sql_query_and_save(
            hwnd, sql_query, PANEL_PATH,
            "Fetching settings from sceneiq_config"
        )

        if not success:
            if error_msg:
                errors_encountered.append(error_msg)
            else:
                error_msg = ("The SQL query 'select settings from sceneiq_config' executed but failed "
                             "to return valid data. Check if the 'sceneiq_config' table exists and contains data.")
                errors_encountered.append(error_msg)
            _set_currently_running("fetch_settings()", status="error",
                                   operation_status=f"❌ {error_msg}")
            return False

        print("✅ [OPERATION] Settings fetched and saved successfully")

        # Final JSON repair
        print("🔄 [REPAIR] Running final JSON repair...")
        repair_success, repaired_data = repair_json_file(PANEL_PATH)
        if repair_success:
            print("✅ [REPAIR] JSON file successfully repaired")
        else:
            print("⚠️ [REPAIR] Could not repair JSON file, but data was saved")
            warnings_encountered.append("JSON data may not be fully valid")

        # SUCCESS – hand off
        _set_currently_running(
            "fetch_settings_successful",
            status="pending",
            operation_status="✅ fetch_settings completed successfully"
        )
        print("🏁 [FETCH] currently_running set to 'fetch_settings_successful'")

        print(f"{'='*60}")
        print("✅ [PHPMYADMIN] OPERATION COMPLETED!")
        print("ℹ️ Browser window remains open for your use.")

        if warnings_encountered:
            print(f"\n⚠️ WARNINGS ({len(warnings_encountered)}):")
            for warning in warnings_encountered[:5]:
                print(f"    - {warning}")
            if len(warnings_encountered) > 5:
                print(f"    ... and {len(warnings_encountered) - 5} more warnings")

        if errors_encountered:
            print(f"\n❌ ERRORS ({len(errors_encountered)}):")
            for error in errors_encountered[:5]:
                print(f"    - {error}")
            if len(errors_encountered) > 5:
                print(f"    ... and {len(errors_encountered) - 5} more errors")

        print(f"{'='*60}\n")
        return len(errors_encountered) == 0

    except KeyboardInterrupt:
        print("🛑 [PHPMYADMIN] Operation interrupted by user")
        try:
            backup_path = PANEL_PATH + ".backup"
            if os.path.exists(backup_path):
                restore_from_backup(PANEL_PATH)
        except Exception:
            pass
        _set_currently_running("fetch_settings()", status="aborted",
                               operation_status="❌ ABORTED: fetch_settings terminated by user")
        return False
    except Exception as e:
        error_msg = f"Unexpected error: {str(e)}"
        print(f"❌ [PHPMYADMIN] {error_msg}")
        traceback.print_exc()
        try:
            backup_path = PANEL_PATH + ".backup"
            if os.path.exists(backup_path):
                restore_from_backup(PANEL_PATH)
        except Exception:
            pass
        _set_currently_running("fetch_settings()", status="error",
                               operation_status=f"❌ {error_msg}")
        return False

def distribute_characters():
    """
    Distributes character visual prompts from characters_visual_prompts to each entry
    based on the view_focus_characters field.

    For each entry, extracts character names from view_focus_characters,
    normalizes them (removes special characters, handles "and" separator),
    looks up each character in characters_visual_prompts, and adds them
    as new fields in the entry.

    IMPORTANT:
    Every distributed character receives the following default instruction
    at the beginning of its character details, even if that character was
    already distributed previously:

    "Don't generate this character image it is just detail and definition
    of the character only generate the scene pov prompt, i repeat dont
    generate this character image but use the details as key and
    information of what the character looks like"

    Executes only when currently_running is None/"none"/empty,
    "fetch_settings_successful", or "distribute_characters()".
    On start sets currently_running to "distribute_characters()".
    On clean success sets currently_running to "distribute_characters_successful".
    """
    import os
    import json
    import traceback

    # ================================================================
    # DEFAULT CHARACTER INSTRUCTION
    # ================================================================
    DEFAULT_CHARACTER_INSTRUCTION = (
        "Don't generate this character image it is just detail and definition "
        "of the character only generate the scene pov prompt, i repeat dont "
        "generate this character image but use the details as key and "
        "information of what the character looks like"
    )

    def _load_panel():
        if not os.path.exists(PANEL_PATH):
            return {}

        try:
            with open(PANEL_PATH, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception:
            return {}

    def _save_panel(data):
        try:
            os.makedirs(
                os.path.dirname(PANEL_PATH) or '.',
                exist_ok=True
            )

            with open(PANEL_PATH, 'w', encoding='utf-8') as f:
                json.dump(
                    data,
                    f,
                    indent=4,
                    ensure_ascii=False
                )

            return True

        except Exception as e:
            print(f"❌ [STATUS] Failed to save panel: {e}")
            return False

    def _get_currently_running():
        data = _load_panel()
        val = data.get("currently_running")

        if val is None:
            return None

        val = str(val).strip()

        if val.lower() in ("", "none", "null"):
            return None

        return val

    def _set_currently_running(
        value,
        status=None,
        operation_status=None
    ):
        data = _load_panel()

        data["currently_running"] = value

        if status is not None:
            data["status"] = status

        if operation_status is not None:
            data["operation_status"] = operation_status

        return _save_panel(data)

    # ================================================================
    # GATE: ONLY RUN WHEN ALLOWED
    # ================================================================
    current = _get_currently_running()

    allowed = (
        current is None
        or current == "fetch_settings_successful"
        or current == "distribute_characters()"
    )

    if not allowed:
        print(
            f"⏭️ [DISTRIBUTE] Skipping – currently_running is "
            f"'{current}' "
            f"(expected None, 'fetch_settings_successful' or "
            f"'distribute_characters()')"
        )
        return None

    # ================================================================
    # CLAIM THE SLOT
    # ================================================================
    _set_currently_running(
        "distribute_characters()",
        status="pending",
        operation_status="Running distribute_characters()"
    )

    print(
        "🔒 [DISTRIBUTE] Claimed currently_running = "
        "'distribute_characters()'"
    )

    # ================================================================
    # MAIN LOGIC
    # ================================================================
    if not os.path.exists(PANEL_PATH):
        msg = f"❌ Error: panel.json missing at: {PANEL_PATH}"

        print(msg)

        _set_currently_running(
            "distribute_characters()",
            status="error",
            operation_status=msg
        )

        return None

    try:
        with open(PANEL_PATH, 'r', encoding='utf-8') as file:
            panel_data = json.load(file)

    except json.JSONDecodeError as e:
        msg = f"❌ Error: panel.json is not valid JSON: {e}"

        print(msg)

        _set_currently_running(
            "distribute_characters()",
            status="error",
            operation_status=msg
        )

        return None

    except Exception as e:
        msg = f"❌ Error reading panel.json: {e}"

        print(msg)

        _set_currently_running(
            "distribute_characters()",
            status="error",
            operation_status=msg
        )

        return None

    # ================================================================
    # GET CHARACTERS_VISUAL_PROMPTS
    # ================================================================
    script_json = (
        panel_data
        .get('project_name', {})
        .get('script_json', {})
    )

    characters_visual_prompts = script_json.get(
        'characters_visual_prompts',
        {}
    )

    if not characters_visual_prompts:
        msg = (
            "⚠️ No characters_visual_prompts found in panel.json "
            "(path: project_name.script_json.characters_visual_prompts)"
        )

        print(msg)

        _set_currently_running(
            "distribute_characters()",
            status="error",
            operation_status=msg
        )

        return None

    # ================================================================
    # GET SCRIPT ENTRIES
    # ================================================================
    script_data = script_json.get('script', {})

    if not script_data:
        msg = "⚠️ No script data found in panel.json"

        print(msg)

        _set_currently_running(
            "distribute_characters()",
            status="error",
            operation_status=msg
        )

        return None

    print(
        f"📊 [DISTRIBUTE] Found "
        f"{len(characters_visual_prompts)} characters in lookup"
    )

    # ================================================================
    # NORMALIZATION
    # ================================================================
    def normalize_name(name):
        if not name:
            return ""

        name = ' '.join(name.split())

        return name.lower().strip()

    # ================================================================
    # BUILD CHARACTER LOOKUP
    # ================================================================
    character_lookup = {}

    for char_name, char_data in characters_visual_prompts.items():
        normalized = normalize_name(char_name)

        if normalized:
            character_lookup[normalized] = {
                'original_name': char_name,
                'data': char_data
            }

    print(
        f"📊 [DISTRIBUTE] Character lookup built with "
        f"{len(character_lookup)} entries"
    )

    # ================================================================
    # COUNTERS
    # ================================================================
    entries_processed = 0
    total_characters_added = 0
    total_character_instructions_applied = 0
    batches_processed = 0

    # ================================================================
    # EXTRACT CHARACTER NAMES
    # ================================================================
    def extract_character_names(view_focus_string):
        if not view_focus_string:
            return []

        normalized = view_focus_string.replace(
            ' and ',
            ', '
        )

        normalized = normalized.replace(
            ' and',
            ','
        )

        normalized = normalized.replace(
            'and ',
            ','
        )

        raw_names = [
            name.strip()
            for name in normalized.split(',')
            if name.strip()
        ]

        return raw_names

    # ================================================================
    # APPLY DEFAULT CHARACTER INSTRUCTION
    # ================================================================
    def apply_default_character_instruction(char_data):
        """
        Adds the required default instruction to the character data.

        The instruction is applied every time distribution runs,
        including when the character was already distributed.

        If the character data is a string:
            prepend the instruction.

        If the character data is a dictionary:
            preserve all existing fields and add the instruction
            as a dedicated field while also ensuring it is represented
            in the character definition.

        If the character data is a list:
            preserve the existing list and add the instruction as
            the first item.

        Other data types are converted safely to a dictionary/string
        representation without losing the original information.
        """

        instruction = DEFAULT_CHARACTER_INSTRUCTION

        # ------------------------------------------------------------
        # STRING CHARACTER DATA
        # ------------------------------------------------------------
        if isinstance(char_data, str):
            existing = char_data.strip()

            if not existing:
                return instruction

            # Prevent duplicate copies while still guaranteeing
            # the instruction exists.
            if instruction.lower() in existing.lower():
                return existing

            return f"{instruction}\n\n{existing}"

        # ------------------------------------------------------------
        # DICTIONARY CHARACTER DATA
        # ------------------------------------------------------------
        if isinstance(char_data, dict):
            updated = dict(char_data)

            # Always explicitly store the instruction.
            updated["character_generation_instruction"] = instruction

            return updated

        # ------------------------------------------------------------
        # LIST CHARACTER DATA
        # ------------------------------------------------------------
        if isinstance(char_data, list):
            updated = list(char_data)

            # Remove an existing exact instruction so we can put one
            # clean copy at the beginning.
            updated = [
                item
                for item in updated
                if not (
                    isinstance(item, str)
                    and item.strip().lower() == instruction.lower()
                )
            ]

            updated.insert(0, instruction)

            return updated

        # ------------------------------------------------------------
        # OTHER DATA TYPES
        # ------------------------------------------------------------
        if char_data is None:
            return {
                "character_generation_instruction": instruction
            }

        return {
            "character_generation_instruction": instruction,
            "character_details": char_data
        }

    # ================================================================
    # PROCESS EACH BATCH
    # ================================================================
    for batch_key, batch_data in script_data.items():

        if not isinstance(batch_data, dict):
            continue

        entries = batch_data.get('entries', [])

        if not entries:
            continue

        batches_processed += 1

        print(
            f"\n📁 [DISTRIBUTE] Processing batch: "
            f"{batch_key} ({len(entries)} entries)"
        )

        # ============================================================
        # PROCESS EACH ENTRY
        # ============================================================
        for entry_idx, entry in enumerate(entries):

            if not isinstance(entry, dict):
                print(
                    f"  ⏭️ Entry {entry_idx + 1}: "
                    f"Invalid entry structure, skipping"
                )
                continue

            view_focus = entry.get(
                'view_focus_characters',
                ''
            )

            if not view_focus:
                print(
                    f"  ⏭️ Entry {entry_idx + 1}: "
                    f"No view_focus_characters, skipping"
                )
                continue

            raw_names = extract_character_names(
                view_focus
            )

            if not raw_names:
                print(
                    f"  ⏭️ Entry {entry_idx + 1}: "
                    f"No names extracted from '{view_focus}'"
                )
                continue

            print(
                f"  📝 Entry {entry_idx + 1}: "
                f"Extracted names: {raw_names}"
            )

            characters_added = 0

            # ========================================================
            # PROCESS EACH CHARACTER
            # ========================================================
            for raw_name in raw_names:

                normalized_raw = normalize_name(
                    raw_name
                )

                matched_char = None

                # ----------------------------------------------------
                # EXACT MATCH
                # ----------------------------------------------------
                if normalized_raw in character_lookup:
                    matched_char = character_lookup[
                        normalized_raw
                    ]

                # ----------------------------------------------------
                # PARTIAL MATCH
                # ----------------------------------------------------
                else:
                    for lookup_key, lookup_data in character_lookup.items():

                        if (
                            normalized_raw in lookup_key
                            or lookup_key in normalized_raw
                        ):
                            matched_char = lookup_data

                            print(
                                f"    🔍 Partial match: "
                                f"'{raw_name}' -> '{lookup_key}'"
                            )

                            break

                # ====================================================
                # CHARACTER FOUND
                # ====================================================
                if matched_char:

                    char_name = matched_char['original_name']
                    char_data = matched_char['data']

                    # ------------------------------------------------
                    # ALWAYS APPLY THE DEFAULT INSTRUCTION
                    # ------------------------------------------------
                    updated_char_data = (
                        apply_default_character_instruction(
                            char_data
                        )
                    )

                    # ------------------------------------------------
                    # DISTRIBUTE / OVERWRITE THE CHARACTER FIELD
                    # ------------------------------------------------
                    entry[char_name] = updated_char_data

                    characters_added += 1
                    total_characters_added += 1
                    total_character_instructions_applied += 1

                    print(
                        f"    ✅ Added/updated character: "
                        f"{char_name}"
                    )

                    print(
                        f"       ↳ Default character instruction "
                        f"applied"
                    )

                # ====================================================
                # CHARACTER NOT FOUND
                # ====================================================
                else:

                    print(
                        f"    ⚠️ Character not found in lookup: "
                        f"'{raw_name}'"
                    )

                    print(
                        f"       Available characters: "
                        f"{list(character_lookup.keys())}"
                    )

            # ========================================================
            # ENTRY SUMMARY
            # ========================================================
            if characters_added > 0:

                entries_processed += 1

                print(
                    f"  ✅ Entry {entry_idx + 1}: "
                    f"Added/updated {characters_added} characters"
                )

    # ================================================================
    # SUMMARY
    # ================================================================
    print(f"\n{'=' * 60}")
    print("📊 [DISTRIBUTE] Summary:")
    print(
        f"   - Batches processed: "
        f"{batches_processed}"
    )
    print(
        f"   - Entries with characters distributed: "
        f"{entries_processed}"
    )
    print(
        f"   - Total character references added/updated: "
        f"{total_characters_added}"
    )
    print(
        f"   - Default character instructions applied: "
        f"{total_character_instructions_applied}"
    )
    print(
        f"   - Default instruction: "
        f"'{DEFAULT_CHARACTER_INSTRUCTION}'"
    )
    print(f"{'=' * 60}")

    # ================================================================
    # SAVE UPDATED PANEL.JSON
    # ================================================================
    try:

        # Preserve / update status fields
        panel_data["currently_running"] = (
            "distribute_characters_successful"
        )

        panel_data["status"] = "pending"

        panel_data["operation_status"] = (
            "✅ distribute_characters completed successfully"
        )

        with open(
            PANEL_PATH,
            'w',
            encoding='utf-8'
        ) as file:

            json.dump(
                panel_data,
                file,
                indent=4,
                ensure_ascii=False
            )

        print(
            "✅ [DISTRIBUTE] Successfully updated panel.json"
        )

        print(
            "🏁 [DISTRIBUTE] currently_running set to "
            "'distribute_characters_successful'"
        )

        return (
            entries_processed,
            total_characters_added
        )

    except Exception as e:

        msg = f"❌ Error saving panel.json: {e}"

        print(msg)

        traceback.print_exc()

        _set_currently_running(
            "distribute_characters()",
            status="error",
            operation_status=msg
        )

        return None
      
def generate_gemini_images():
    """
    Generates images using Gemini via GUI automation with pyautogui image recognition.
    Features: 
    - Uses GEMINI_URL and GUI_IMAGES path variable
    - Checks panel.json for image_generation_engine == "Gemini" (case-insensitive)
    - If engine is not Gemini, function exits immediately
    - Runs only when currently_running is None/"none"/empty,
      "distribute_characters_successful", or "generate_gemini_images()"
    - On start sets currently_running to "generate_gemini_images()"
    - On clean success sets currently_running to "generate_gemini_images_successful"
    - On any error/abort keeps currently_running as "generate_gemini_images()"
    - Waits for ask_gemini.png to appear, clicks and pastes full entry JSON
    - Looks for upload_to_gemini.png to confirm paste, clicks on RIGHT side (not center)
    - Waits for generating_image.png or gemini_microphone.png
    - Checks for gemini_generation_limit_message.png - if found, terminates
    - When gemini_microphone.png is found, FIRST checks for limit message
    - If limit message found, aborts; otherwise proceeds to find more.png
    - Clicks more.png on the right side (not center)
    - Clicks download_gemini_image.png
    - Monitors downloads with background function
    - If download not found after 30 seconds, retries: click more.png, then download_gemini_image.png
    - Renames downloaded file to entry name (entry_1.png, entry_2.png, etc.)
    - Moves to images folder in project title directory
    - Validates entry order and handles missing entries
    - No JSON tracking for entries
    - Dynamic fallback support: checks for image_name{number}.png variants
    - Enhanced upload detection: waits indefinitely for upload button if text is already pasted
    """
    import os
    import json
    import time
    import re
    import subprocess
    import traceback

    try:
        import pyautogui
        import pyperclip
        import win32api
        import win32con
        import win32gui
        import win32process
        import psutil
        import keyboard
    except ImportError as e:
        print(f"❌ [GEMINI] Missing dependency: {e}")
        return

    pyautogui.PAUSE = 0.0

    # ------------------------------------------------------------------
    # Status helpers (self-contained)
    # ------------------------------------------------------------------
    def _load_panel():
        if not os.path.exists(PANEL_PATH):
            return {}
        try:
            with open(PANEL_PATH, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception:
            return {}

    def _save_panel(data):
        try:
            os.makedirs(os.path.dirname(PANEL_PATH) or '.', exist_ok=True)
            with open(PANEL_PATH, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=4, ensure_ascii=False)
            return True
        except Exception as e:
            print(f"❌ [STATUS] Failed to save panel: {e}")
            return False

    def _get_currently_running():
        data = _load_panel()
        val = data.get("currently_running")
        if val is None:
            return None
        val = str(val).strip()
        if val.lower() in ("", "none", "null"):
            return None
        return val

    def _set_currently_running(value, status=None, operation_status=None):
        data = _load_panel()
        data["currently_running"] = value
        if status is not None:
            data["status"] = status
        if operation_status is not None:
            data["operation_status"] = operation_status
        return _save_panel(data)

    # ------------------------------------------------------------------
    # Engine + status gate
    # ------------------------------------------------------------------
    if not os.path.exists(PANEL_PATH):
        print(f"❌ Error: panel.json missing at: {PANEL_PATH}")
        return

    try:
        with open(PANEL_PATH, 'r', encoding='utf-8') as file:
            panel_data = json.load(file)

        engines = panel_data.get('engines', {})
        image_generation_engine = engines.get('image_generation_engine', '').strip()

        if image_generation_engine.lower() != 'gemini':
            print(f"ℹ️ [ENGINE] Image generation engine is '{image_generation_engine}', not 'Gemini' - skipping operation")
            print(f"   To use this function, set 'image_generation_engine' to 'Gemini' in panel.json")
            return

        print(f"✅ [ENGINE] Image generation engine is 'Gemini' - proceeding with operation")

    except json.JSONDecodeError as e:
        print(f"❌ Error: panel.json is not valid JSON: {e}")
        return
    except Exception as e:
        print(f"❌ Error reading panel.json: {e}")
        return

    current = _get_currently_running()
    allowed = (
        current is None
        or current == "distribute_characters_successful"
        or current == "generate_gemini_images()"
    )
    if not allowed:
        print(f"⏭️ [GEMINI] Skipping – currently_running is '{current}' "
              f"(expected None, 'distribute_characters_successful' or 'generate_gemini_images()')")
        return

    # Claim the slot
    _set_currently_running(
        "generate_gemini_images()",
        status="pending",
        operation_status="Running generate_gemini_images()"
    )
    print("🔒 [GEMINI] Claimed currently_running = 'generate_gemini_images()'")

    # ------------------------------------------------------------------
    # Rest of original logic (with status updates on success / error)
    # ------------------------------------------------------------------
    project_title = panel_data.get('project_name', {}).get('project_title', 'workproject1')
    script_json = panel_data.get('project_name', {}).get('script_json', {})
    script_data = script_json.get('script', {})

    all_entries = []
    for batch_key, batch_data in script_data.items():
        if isinstance(batch_data, dict) and 'entries' in batch_data:
            entries = batch_data.get('entries', [])
            for entry in entries:
                if 'scene_pov_prompt' in entry:
                    all_entries.append(entry)

    total_entries = len(all_entries)
    print(f"📊 [CONFIG] Found {total_entries} total entries")

    if total_entries == 0:
        print("❌ No entries found to process")
        _set_currently_running("generate_gemini_images()", status="error",
                               operation_status="❌ No entries found to process")
        return

    terminate_automation = False
    operation_status_flag = True
    operation_status_message = ""
    operation_aborted = False

    def update_operation_status(message, is_error=False, is_abort=False, is_success=False):
        nonlocal operation_status_message, operation_status_flag, operation_aborted
        try:
            with open(PANEL_PATH, 'r', encoding='utf-8') as file:
                current_panel = json.load(file)

            if is_abort:
                operation_status_message = f"❌ ABORTED: {message}"
                operation_status_flag = False
                operation_aborted = True
            elif is_error:
                operation_status_message = f"⚠️ ERROR: {message}"
                operation_status_flag = False
            elif is_success:
                operation_status_message = f"✅ {message}"
                operation_status_flag = True
            else:
                operation_status_message = f"ℹ️ {message}"

            current_panel['operation_status'] = operation_status_message
            # Keep currently_running as the function name on error/abort
            if is_error or is_abort:
                current_panel['currently_running'] = "generate_gemini_images()"
                current_panel['status'] = "error" if is_error else "aborted"
            elif is_success:
                # Final success is handled at the very end; intermediate successes keep the running name
                current_panel['currently_running'] = "generate_gemini_images()"
                current_panel['status'] = "pending"

            with open(PANEL_PATH, 'w', encoding='utf-8') as file:
                json.dump(current_panel, file, indent=4, ensure_ascii=False)

            if is_abort:
                print(f"🛑 [STATUS] Operation aborted: {message}")
                raise SystemExit(f"Operation aborted: {message}")
        except SystemExit:
            raise
        except Exception as e:
            print(f"⚠️ [STATUS] Failed to update operation status: {e}")

    def abort_operation(reason):
        print(f"🛑 [ABORT] Aborting operation: {reason}")
        update_operation_status(f"Aborting Gemini image generation: {reason}", is_abort=True)

    def check_operation_status():
        if not operation_status_flag or operation_aborted:
            print("🛑 [STATUS] Operation status is invalid - aborting")
            update_operation_status("Operation status invalid - aborting Gemini operation", is_abort=True)
            return False
        return True

    def on_terminate_shortcut():
        nonlocal terminate_automation
        try:
            hud.print("🛑 Manual Stop Triggered!", "warning")
        except Exception:
            pass
        print("🛑 Manual Stop Triggered!")
        terminate_automation = True
        update_operation_status("Gemini operation manually terminated by user (Alt+/)", is_abort=True)

    keyboard.add_hotkey('alt+/', on_terminate_shortcut)

    def check_for_termination():
        if terminate_automation:
            update_operation_status("Gemini operation terminated by user", is_abort=True)
            raise KeyboardInterrupt("User forced exit via shortcut key.")
        if not check_operation_status():
            raise SystemExit("Operation status invalid")

    # --- DYNAMIC FALLBACK HELPERS ---
    def get_image_variants(base_name):
        variants = []
        base_name_without_ext = os.path.splitext(base_name)[0]
        ext = os.path.splitext(base_name)[1]
        original_path = os.path.join(GUI_IMAGES, base_name)
        if os.path.exists(original_path):
            variants.append(original_path)
        pattern = re.compile(rf'^{re.escape(base_name_without_ext)}(\d+)\.{re.escape(ext[1:])}$', re.IGNORECASE)
        try:
            files = os.listdir(GUI_IMAGES)
            numbered_variants = []
            for filename in files:
                match = pattern.match(filename)
                if match:
                    number = int(match.group(1))
                    full_path = os.path.join(GUI_IMAGES, filename)
                    numbered_variants.append((number, full_path))
            numbered_variants.sort(key=lambda x: x[0])
            for _, full_path in numbered_variants:
                variants.append(full_path)
        except Exception as e:
            print(f"⚠️ [FALLBACK] Error scanning for variants of {base_name}: {e}")
        return variants

    def find_image_with_fallback(image_name, confidence=0.8, region=None):
        variants = get_image_variants(image_name)
        if not variants:
            print(f"❌ [FALLBACK] No variants found for {image_name}")
            return None, None
        print(f"🔍 [FALLBACK] Searching for {image_name} with {len(variants)} variant(s)...")
        for variant_path in variants:
            variant_name = os.path.basename(variant_path)
            try:
                location = pyautogui.locateCenterOnScreen(
                    variant_path, confidence=confidence, grayscale=False
                )
                if location:
                    print(f"✅ [FALLBACK] Found {variant_name} at ({location.x}, {location.y})")
                    return location, variant_path
            except Exception:
                pass
        return None, None

    def wait_for_image_with_fallback(image_name, timeout_seconds=60, confidence=0.8, region=None):
        variants = get_image_variants(image_name)
        if not variants:
            print(f"❌ [FALLBACK] No variants found for {image_name}")
            return None, None
        print(f"🔍 [FALLBACK] Waiting for {image_name} with {len(variants)} variant(s)...")
        start_time = time.time()
        last_status_time = 0
        while time.time() - start_time < timeout_seconds:
            check_for_termination()
            for variant_path in variants:
                variant_name = os.path.basename(variant_path)
                try:
                    location = pyautogui.locateCenterOnScreen(
                        variant_path, confidence=confidence, grayscale=False
                    )
                    if location:
                        print(f"✅ [FALLBACK] Found {variant_name} at ({location.x}, {location.y})")
                        return location, variant_path
                except Exception:
                    pass
            current_time = time.time()
            if current_time - last_status_time > 5:
                elapsed = int(current_time - start_time)
                print(f"⏳ [FALLBACK] Still waiting for {image_name}... ({elapsed}s elapsed)")
                last_status_time = current_time
            time.sleep(0.3)
        print(f"❌ [FALLBACK] {image_name} not found in any variant within {timeout_seconds} seconds")
        return None, None

    def find_image_location_with_fallback(image_name, confidence=0.8, region=None):
        variants = get_image_variants(image_name)
        if not variants:
            print(f"❌ [FALLBACK] No variants found for {image_name}")
            return None, None
        for variant_path in variants:
            variant_name = os.path.basename(variant_path)
            try:
                location = pyautogui.locateOnScreen(
                    variant_path, confidence=confidence, grayscale=False
                )
                if location:
                    print(f"✅ [FALLBACK] Found {variant_name} at ({location.left}, {location.top})")
                    return location, variant_path
            except Exception:
                pass
        return None, None

    # --- WINDOW MANAGEMENT HELPERS ---
    def get_current_monitor():
        try:
            cursor_pos = win32api.GetCursorPos()
            monitor_info = win32api.GetMonitorInfo(win32api.MonitorFromPoint(cursor_pos))
            return monitor_info['Monitor']
        except Exception:
            return (0, 0, win32api.GetSystemMetrics(win32con.SM_CXSCREEN),
                    win32api.GetSystemMetrics(win32con.SM_CYSCREEN))

    def get_edge_window_on_monitor(monitor_bounds):
        monitor_left, monitor_top, monitor_right, monitor_bottom = monitor_bounds
        edge_windows = []
        edge_process_names = ["msedge.exe"]

        def enum_windows_callback(hwnd, windows):
            if win32gui.IsWindowVisible(hwnd):
                try:
                    _, pid = win32process.GetWindowThreadProcessId(hwnd)
                    process = psutil.Process(pid)
                    if process.name().lower() in edge_process_names:
                        rect = win32gui.GetWindowRect(hwnd)
                        left, top, right, bottom = rect
                        width, height = right - left, bottom - top
                        if width > 200 and height > 200:
                            window_center_x = (left + right) / 2
                            window_center_y = (top + bottom) / 2
                            is_on_current_monitor = (
                                monitor_left <= window_center_x <= monitor_right and
                                monitor_top <= window_center_y <= monitor_bottom
                            )
                            if is_on_current_monitor:
                                windows.append({'hwnd': hwnd, 'width': width, 'height': height})
                except Exception:
                    pass
            return True

        win32gui.EnumWindows(enum_windows_callback, edge_windows)
        edge_windows.sort(key=lambda w: w['width'] * w['height'], reverse=True)
        return edge_windows

    def ensure_edge_window_ready():
        check_for_termination()
        current_monitor = get_current_monitor()
        edge_windows = get_edge_window_on_monitor(current_monitor)

        if edge_windows:
            hwnd = edge_windows[0]['hwnd']
            print(f"🪟 [WINDOW] Found existing Edge window handle: {hwnd}")
            try:
                if win32gui.IsIconic(hwnd):
                    print("🔄 [WINDOW] Window was minimized, restoring...")
                    win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                    time.sleep(0.3)
                print("🔄 [WINDOW] Maximizing window...")
                win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                time.sleep(0.5)
                win32gui.SetForegroundWindow(hwnd)
                time.sleep(0.2)
                print("✅ [WINDOW] Window ready - maximized and focused")
                update_operation_status("Browser window ready for Gemini operation")
                return hwnd
            except Exception as e:
                print(f"⚠️ [WINDOW] Error preparing existing window: {e}")

        print("💻 [WINDOW] No Edge window found, launching new instance...")
        update_operation_status("Launching browser for Gemini operation...")
        subprocess.Popen([edge_path, "about:blank"])

        for attempt in range(20):
            check_for_termination()
            time.sleep(0.5)
            edge_windows = get_edge_window_on_monitor(current_monitor)
            if edge_windows:
                hwnd = edge_windows[0]['hwnd']
                print(f"🪟 [WINDOW] New Edge window launched, handle: {hwnd}")
                try:
                    if win32gui.IsIconic(hwnd):
                        win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                        time.sleep(0.3)
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.5)
                    win32gui.SetForegroundWindow(hwnd)
                    time.sleep(0.2)
                    print("✅ [WINDOW] New window ready - maximized and focused")
                    update_operation_status("Browser launched for Gemini operation")
                    return hwnd
                except Exception as e:
                    print(f"⚠️ [WINDOW] Error preparing new window: {e}")
                    continue

        error_msg = "Failed to get or launch Edge window for Gemini operation"
        update_operation_status(error_msg, is_error=True)
        abort_operation(error_msg)
        raise RuntimeError(error_msg)

    def enforce_window_focus(hwnd):
        check_for_termination()
        try:
            if not win32gui.IsWindow(hwnd):
                print("⚠️ [FOCUS] Window handle invalid, reacquiring...")
                return False
            if win32gui.IsIconic(hwnd):
                print("🔄 [FOCUS] Window was minimized, restoring...")
                win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                time.sleep(0.3)
            current_foreground = win32gui.GetForegroundWindow()
            if current_foreground != hwnd:
                print("🛡️ [FOCUS] Correcting window focus...")
                win32gui.SetForegroundWindow(hwnd)
                time.sleep(0.15)
            try:
                placement = win32gui.GetWindowPlacement(hwnd)
                if placement[1] != win32con.SW_SHOWMAXIMIZED:
                    print("🔄 [FOCUS] Window not maximized, maximizing...")
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.3)
            except Exception:
                try:
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.3)
                except Exception:
                    pass
            return True
        except Exception as e:
            print(f"⚠️ [FOCUS] Focus correction exception: {e}")
            return False

    def ensure_window_ready_and_focused():
        check_for_termination()
        hwnd = ensure_edge_window_ready()
        enforce_window_focus(hwnd)
        return hwnd

    def fast_paste_url(hwnd, url):
        check_for_termination()
        try:
            hud.print("📋 Navigating to URL...", "typing")
        except Exception:
            pass
        print(f"📋 Pasting URL: {url}")
        pyperclip.copy(url)
        enforce_window_focus(hwnd)
        pyautogui.hotkey('ctrl', 'l')
        time.sleep(0.1)
        enforce_window_focus(hwnd)
        pyautogui.hotkey('ctrl', 'v')
        pyautogui.press('enter')
        update_operation_status("Navigating to Gemini URL...")

    # --- IMAGE RECOGNITION HELPERS ---
    def wait_for_image(image_name, timeout_seconds=60, confidence=0.8, region=None):
        location, _ = wait_for_image_with_fallback(image_name, timeout_seconds, confidence, region)
        return location

    def find_image(image_name, confidence=0.8, region=None):
        location, _ = find_image_with_fallback(image_name, confidence, region)
        return location

    def click_image_center(image_name, confidence=0.8, wait_time=0.3):
        location = find_image(image_name, confidence)
        if location:
            pyautogui.moveTo(location.x, location.y, duration=0.1)
            pyautogui.click()
            time.sleep(wait_time)
            return True
        return False

    def click_image_right(image_name, confidence=0.8, wait_time=0.3):
        variants = get_image_variants(image_name)
        if not variants:
            print(f"❌ [IMAGE] No variants found for {image_name}")
            return False
        for variant_path in variants:
            variant_name = os.path.basename(variant_path)
            try:
                location = pyautogui.locateOnScreen(variant_path, confidence=confidence, grayscale=False)
                if location:
                    click_x = location.left + int(location.width * 0.75)
                    click_y = location.top + int(location.height / 2)
                    print(f"✅ [IMAGE] Found {variant_name}, clicking right side at ({click_x}, {click_y})")
                    pyautogui.moveTo(click_x, click_y, duration=0.1)
                    pyautogui.click()
                    time.sleep(wait_time)
                    return True
            except Exception:
                pass
        print(f"❌ [IMAGE] {image_name} not found in any variant")
        return False

    def scroll_up_multiple(times=3):
        screen_width = win32api.GetSystemMetrics(win32con.SM_CXSCREEN)
        screen_height = win32api.GetSystemMetrics(win32con.SM_CYSCREEN)
        center_x = screen_width // 2
        center_y = screen_height // 2
        for i in range(times):
            pyautogui.moveTo(center_x, center_y, duration=0.05)
            pyautogui.scroll(-500)
            time.sleep(0.3)
            print(f"⬆️ [SCROLL] Scrolled up ({i+1}/{times})")
        time.sleep(0.5)

    # --- DOWNLOAD MONITORING ---
    def check_and_rename_download(entry_name, images_folder, timeout_seconds=180, check_interval=0.3):
        print(f"📊 [DOWNLOAD] Monitoring for download for {entry_name}...")
        try:
            hud.print("📊 Monitoring download...", "waiting")
        except Exception:
            pass
        update_operation_status(f"Waiting for download: {entry_name}")

        downloads_folder = os.path.expanduser("~/Downloads")
        if not os.path.exists(downloads_folder):
            print(f"❌ [DOWNLOAD] Downloads folder not found: {downloads_folder}")
            return False, None

        initial_files = {}
        try:
            with os.scandir(downloads_folder) as entries:
                for entry in entries:
                    if entry.is_file():
                        try:
                            stat = entry.stat()
                            initial_files[entry.name] = {
                                'path': entry.path, 'time': stat.st_mtime, 'size': stat.st_size
                            }
                        except Exception:
                            pass
            print(f"📁 [DOWNLOAD] Found {len(initial_files)} existing files before download")
        except Exception as e:
            print(f"⚠️ [DOWNLOAD] Error scanning existing files: {e}")
            initial_files = {}

        start_time = time.time()
        download_detected = False
        downloaded_file_path = None
        image_extensions = {'.png', '.jpg', '.jpeg', '.gif', '.webp', '.bmp', '.tiff'}

        while time.time() - start_time < timeout_seconds:
            check_for_termination()
            elapsed = int(time.time() - start_time)
            try:
                if os.path.exists(downloads_folder):
                    with os.scandir(downloads_folder) as entries:
                        for entry in entries:
                            if entry.is_file():
                                file_name = entry.name
                                file_ext = os.path.splitext(file_name)[1].lower()
                                is_new_file = file_name not in initial_files
                                is_recent = False
                                try:
                                    stat = entry.stat()
                                    if stat.st_mtime > start_time - 10:
                                        is_recent = True
                                except Exception:
                                    pass
                                if (is_new_file or is_recent) and file_ext in image_extensions:
                                    print(f"🆕 [DOWNLOAD] Found new/updated image file: {file_name}")
                                    print(f"   📁 Path: {entry.path}")
                                    print(f"   📏 Size: {stat.st_size} bytes")
                                    stable_count = 0
                                    stable_size = 0
                                    max_stable_checks = 5
                                    while stable_count < 3 and stable_count < max_stable_checks:
                                        try:
                                            current_size = os.path.getsize(entry.path)
                                            if current_size == stable_size and current_size > 1024:
                                                stable_count += 1
                                                print(f"   ⏳ File stable ({stable_count}/3), size: {current_size} bytes")
                                            else:
                                                stable_count = 0
                                                stable_size = current_size
                                                print(f"   ⏳ File size changing: {current_size} bytes")
                                        except Exception as e:
                                            print(f"   ⚠️ Error checking size: {e}")
                                        time.sleep(0.3)
                                    if stable_count >= 3:
                                        print(f"✅ [DOWNLOAD] File stable and ready!")
                                        downloaded_file_path = entry.path
                                        download_detected = True
                                        break
                                    else:
                                        print(f"⚠️ [DOWNLOAD] File not stable, continuing to monitor...")
                                elif file_name.endswith('.crdownload') or file_name.endswith('.tmp'):
                                    print(f"⏳ [DOWNLOAD] Found temp download: {file_name}")
                            if download_detected:
                                break
                if download_detected and downloaded_file_path:
                    break
                if elapsed % 10 == 0 and elapsed > 0:
                    print(f"⏳ [DOWNLOAD] Still waiting for download... ({elapsed}s elapsed)")
                    try:
                        hud.print(f"⏳ Waiting... ({elapsed}s)", "waiting")
                    except Exception:
                        pass
                    update_operation_status(f"Waiting for download {entry_name}... ({elapsed}s)")
                time.sleep(check_interval)
            except Exception as e:
                print(f"⚠️ [DOWNLOAD] Error in monitoring loop: {e}")
                time.sleep(check_interval)
                continue

        if not download_detected or not downloaded_file_path:
            print(f"⏰ [DOWNLOAD] Timeout or no download detected after {timeout_seconds} seconds")
            try:
                print(f"🔍 [DOWNLOAD] Performing final scan for new image files...")
                with os.scandir(downloads_folder) as entries:
                    for entry in entries:
                        if entry.is_file():
                            file_name = entry.name
                            file_ext = os.path.splitext(file_name)[1].lower()
                            if file_ext in image_extensions:
                                try:
                                    stat = entry.stat()
                                    if stat.st_mtime > start_time - 10:
                                        print(f"🆕 [DOWNLOAD] Found new file in final scan: {file_name}")
                                        downloaded_file_path = entry.path
                                        download_detected = True
                                        break
                                except Exception:
                                    pass
            except Exception as e:
                print(f"⚠️ [DOWNLOAD] Final scan error: {e}")

        if not download_detected or not downloaded_file_path:
            print(f"❌ [DOWNLOAD] No download detected")
            return False, None

        try:
            file_name = os.path.basename(downloaded_file_path)
            file_ext = os.path.splitext(file_name)[1].lower()
            new_filename = f"{entry_name}{file_ext}"
            target_path = os.path.join(images_folder, new_filename)
            if not os.path.exists(images_folder):
                os.makedirs(images_folder, exist_ok=True)
            if not os.path.exists(downloaded_file_path):
                print(f"❌ [DOWNLOAD] File no longer exists: {downloaded_file_path}")
                return False, None
            try:
                time.sleep(0.5)
                os.rename(downloaded_file_path, target_path)
                print(f"✅ [DOWNLOAD] File renamed to: {new_filename}")
                print(f"📁 [DOWNLOAD] Moved to: {target_path}")
                try:
                    hud.print(f"✅ Saved: {new_filename}", "success")
                except Exception:
                    pass
                update_operation_status(f"Downloaded and saved: {new_filename}")
                return True, target_path
            except Exception as e:
                print(f"⚠️ [DOWNLOAD] Error moving file: {e}")
                import shutil
                try:
                    shutil.copy2(downloaded_file_path, target_path)
                    try:
                        os.remove(downloaded_file_path)
                    except Exception:
                        pass
                    print(f"✅ [DOWNLOAD] File copied and renamed: {new_filename}")
                    return True, target_path
                except Exception as e2:
                    print(f"❌ [DOWNLOAD] Failed to save file: {e2}")
                    return False, None
        except Exception as e:
            print(f"❌ [DOWNLOAD] Error processing downloaded file: {e}")
            return False, None

    def click_more_and_download(hwnd, entry_name, max_retries=3):
        print(f"🔄 [RETRY] Attempting to click more.png and download for {entry_name}...")
        for attempt in range(max_retries):
            check_for_termination()
            limit_location = find_image("gemini_generation_limit_message.png", confidence=0.7)
            if limit_location:
                print(f"❌ [RETRY] Generation limit reached! Aborting.")
                try:
                    hud.print("❌ Generation limit reached!", "error")
                except Exception:
                    pass
                update_operation_status(f"Generation limit reached for {entry_name}", is_error=True)
                abort_operation(f"Generation limit reached for {entry_name}")
                return False
            scroll_up_multiple(3)
            time.sleep(0.5)
            more_location, more_path = find_image_with_fallback("more.png", confidence=0.8)
            if more_location and more_path:
                try:
                    location = pyautogui.locateOnScreen(more_path, confidence=0.8)
                    if location:
                        click_x = location.left + int(location.width * 0.75)
                        click_y = location.top + int(location.height / 2)
                        print(f"🎯 [RETRY] Clicking {os.path.basename(more_path)} right side at ({click_x}, {click_y}) (attempt {attempt+1})")
                        pyautogui.moveTo(click_x, click_y, duration=0.1)
                        pyautogui.click()
                        time.sleep(0.5)
                        print(f"✅ [RETRY] Clicked more.png right side")
                    else:
                        print(f"⚠️ [RETRY] Could not get bounds for {os.path.basename(more_path)}")
                        scroll_up_multiple(2)
                        time.sleep(0.3)
                        continue
                except Exception as e:
                    print(f"⚠️ [RETRY] Error clicking more.png: {e}")
                    scroll_up_multiple(2)
                    time.sleep(0.3)
                    continue
            else:
                print(f"⚠️ [RETRY] more.png not found (attempt {attempt+1}/{max_retries}) - scrolling...")
                scroll_up_multiple(3)
                time.sleep(0.5)
                continue
            download_location = wait_for_image("download_gemini_image.png", timeout_seconds=10, confidence=0.8)
            if download_location:
                pyautogui.moveTo(download_location.x, download_location.y, duration=0.1)
                pyautogui.click()
                time.sleep(0.5)
                print(f"✅ [RETRY] Download initiated (attempt {attempt+1})")
                return True
            else:
                print(f"⚠️ [RETRY] download_gemini_image.png not found (attempt {attempt+1}/{max_retries})")
                scroll_up_multiple(2)
                time.sleep(0.3)
                download_location = wait_for_image("download_gemini_image.png", timeout_seconds=10, confidence=0.8)
                if download_location:
                    pyautogui.moveTo(download_location.x, download_location.y, duration=0.1)
                    pyautogui.click()
                    time.sleep(0.5)
                    print(f"✅ [RETRY] Download initiated after scroll (attempt {attempt+1})")
                    return True
        print(f"❌ [RETRY] Failed to initiate download after {max_retries} attempts")
        return False

    # --- ENTRY ORDER VALIDATION ---
    def validate_entry_order(images_folder):
        if not os.path.exists(images_folder):
            os.makedirs(images_folder, exist_ok=True)
            return True, 0
        image_files = []
        for file in os.listdir(images_folder):
            if file.endswith(('.png', '.jpg', '.jpeg', '.gif', '.webp')):
                image_files.append(file)
        if not image_files:
            print(f"📁 [VALIDATE] No image files found in {images_folder}")
            return True, 0
        entry_numbers = []
        for filename in image_files:
            match = re.search(r'entry[_\-]?(\d+)\.(png|jpg|jpeg|gif|webp)$', filename, re.IGNORECASE)
            if match:
                entry_numbers.append(int(match.group(1)))
        if not entry_numbers:
            print(f"⚠️ [VALIDATE] No valid entry files found, starting from 0")
            return True, 0
        entry_numbers.sort()
        print(f"📊 [VALIDATE] Found entry numbers: {entry_numbers}")
        expected_next = 1
        for num in entry_numbers:
            if num != expected_next:
                print(f"❌ [VALIDATE] Gap detected: Expected entry {expected_next}, found entry {num}")
                print(f"🗑️ [VALIDATE] Deleting all image files and starting over...")
                try:
                    hud.print("🗑️ Resetting due to missing entries...", "warning")
                except Exception:
                    pass
                update_operation_status("Gap detected - resetting all entries")
                for file in image_files:
                    file_path = os.path.join(images_folder, file)
                    try:
                        os.remove(file_path)
                        print(f"🗑️ [VALIDATE] Deleted: {file}")
                    except Exception as e:
                        print(f"⚠️ [VALIDATE] Could not delete {file}: {e}")
                return True, 0
            expected_next += 1
        next_entry = len(entry_numbers)
        print(f"✅ [VALIDATE] Entries are in order, next entry: {next_entry + 1}")
        return True, next_entry

    def format_entry_json(entry_data):
        try:
            return json.dumps(entry_data, indent=4, ensure_ascii=False)
        except Exception as e:
            print(f"⚠️ [FORMAT] Error formatting JSON: {e}")
            return entry_data.get('scene_pov_prompt', '')

    # --- MAIN GEMINI WORKFLOW HELPERS ---
    def load_gemini_url(hwnd):
        print(f"🌐 [GEMINI] Loading Gemini URL: {GEMINI_URL}")
        try:
            hud.print("📋 Loading Gemini...", "navigating")
        except Exception:
            pass
        update_operation_status("Loading Gemini...")
        fast_paste_url(hwnd, GEMINI_URL)
        time.sleep(3)
        hwnd = ensure_window_ready_and_focused()
        return True, hwnd

    def wait_for_generation_complete(hwnd, entry_name, max_wait_seconds=300, check_interval=0.5):
        print(f"⏳ [ENTRY] Waiting for generation to complete...")
        try:
            hud.print("⏳ Generating image...", "processing")
        except Exception:
            pass
        update_operation_status(f"Generating image for {entry_name}...")

        start_time = time.time()
        last_scroll_time = 0
        scroll_interval = 5
        found_generating = False
        generating_first_seen = None

        print(f"🔍 [ENTRY] Phase 1: Waiting for generating_image.png to confirm work has started...")
        while time.time() - start_time < max_wait_seconds:
            check_for_termination()
            enforce_window_focus(hwnd)
            limit_location = find_image("gemini_generation_limit_message.png", confidence=0.7)
            if limit_location:
                print(f"❌ [ENTRY] Generation limit reached while waiting for generating_image.png!")
                try:
                    hud.print("❌ Generation limit reached!", "error")
                except Exception:
                    pass
                update_operation_status(f"Generation limit reached for {entry_name}", is_error=True)
                return False, "limit_reached"
            generating_location = find_image("generating_image.png", confidence=0.7)
            if generating_location:
                print(f"✅ [ENTRY] Found generating_image.png - work has started!")
                found_generating = True
                generating_first_seen = time.time()
                try:
                    hud.print("⏳ Generation in progress...", "processing")
                except Exception:
                    pass
                update_operation_status(f"Generation in progress for {entry_name}")
                break
            elapsed = int(time.time() - start_time)
            if elapsed % 10 == 0 and elapsed > 0:
                print(f"⏳ [ENTRY] Waiting for generation to start... ({elapsed}s elapsed)")
                try:
                    hud.print(f"⏳ Waiting to start... ({elapsed}s)", "processing")
                except Exception:
                    pass
                update_operation_status(f"Waiting for generation to start for {entry_name}... ({elapsed}s)")
            if time.time() - last_scroll_time > scroll_interval:
                scroll_up_multiple(2)
                last_scroll_time = time.time()
            time.sleep(check_interval)

        if not found_generating:
            print(f"❌ [ENTRY] generating_image.png not found within timeout - generation may not have started")
            return False, "timeout"

        print(f"🔍 [ENTRY] Phase 2: Work confirmed, waiting for gemini_microphone.png (generation complete)...")
        try:
            hud.print("⏳ Generating image...", "processing")
        except Exception:
            pass
        update_operation_status("Generation in progress, waiting for completion...")

        phase2_start = time.time()
        phase2_timeout = max_wait_seconds - (generating_first_seen - start_time) if generating_first_seen else max_wait_seconds - 30
        if phase2_timeout < 30:
            phase2_timeout = 30
            print(f"⚠️ [ENTRY] Adjusted phase 2 timeout to {phase2_timeout}s")

        while time.time() - phase2_start < phase2_timeout:
            check_for_termination()
            enforce_window_focus(hwnd)
            limit_location = find_image("gemini_generation_limit_message.png", confidence=0.7)
            if limit_location:
                print(f"❌ [ENTRY] Generation limit reached! Found gemini_generation_limit_message.png")
                try:
                    hud.print("❌ Generation limit reached!", "error")
                except Exception:
                    pass
                update_operation_status(f"Generation limit reached for {entry_name}", is_error=True)
                return False, "limit_reached"
            microphone_location = find_image("gemini_microphone.png", confidence=0.7)
            if microphone_location:
                print(f"✅ [ENTRY] Found gemini_microphone.png - image is ready!")
                try:
                    hud.print("✅ Image ready", "success")
                except Exception:
                    pass
                update_operation_status(f"Image generation complete for {entry_name}")
                return True, "complete"
            generating_location = find_image("generating_image.png", confidence=0.7)
            if generating_location:
                elapsed = int(time.time() - phase2_start)
                if elapsed % 10 == 0 and elapsed > 0:
                    print(f"⏳ [ENTRY] Still generating... ({elapsed}s elapsed in phase 2)")
                    try:
                        hud.print(f"⏳ Generating... ({elapsed}s)", "processing")
                    except Exception:
                        pass
                    update_operation_status(f"Generating image for {entry_name}... ({elapsed}s)")
                if time.time() - last_scroll_time > scroll_interval:
                    scroll_up_multiple(3)
                    last_scroll_time = time.time()
                time.sleep(check_interval)
                continue
            else:
                print(f"⚠️ [ENTRY] generating_image.png disappeared, checking for microphone...")
                time.sleep(0.5)
                microphone_location = find_image("gemini_microphone.png", confidence=0.7)
                if microphone_location:
                    print(f"✅ [ENTRY] Found gemini_microphone.png - image is ready!")
                    try:
                        hud.print("✅ Image ready", "success")
                    except Exception:
                        pass
                    update_operation_status(f"Image generation complete for {entry_name}")
                    return True, "complete"
                continue

        print(f"⏰ [ENTRY] Generation timeout after {max_wait_seconds} seconds")
        return False, "timeout"

    def wait_for_upload_button_indefinitely(hwnd, entry_name, check_interval=0.5):
        print(f"⏳ [UPLOAD] Waiting indefinitely for upload_to_gemini.png button...")
        try:
            hud.print("⏳ Waiting for upload button...", "waiting")
        except Exception:
            pass
        update_operation_status("Waiting for upload button to appear...")
        last_status_time = 0
        start_time = time.time()
        while True:
            check_for_termination()
            enforce_window_focus(hwnd)
            upload_location = find_image("upload_to_gemini.png", confidence=0.7)
            if upload_location:
                print(f"✅ [UPLOAD] Found upload_to_gemini.png after waiting!")
                return upload_location
            limit_location = find_image("gemini_generation_limit_message.png", confidence=0.7)
            if limit_location:
                print(f"❌ [UPLOAD] Generation limit reached while waiting for upload button!")
                try:
                    hud.print("❌ Generation limit reached!", "error")
                except Exception:
                    pass
                update_operation_status(f"Generation limit reached while waiting for upload for {entry_name}", is_error=True)
                return None
            current_time = time.time()
            if current_time - last_status_time > 5:
                elapsed = int(current_time - start_time)
                print(f"⏳ [UPLOAD] Still waiting for upload button... ({elapsed}s elapsed)")
                try:
                    hud.print(f"⏳ Waiting for upload... ({elapsed}s)", "waiting")
                except Exception:
                    pass
                update_operation_status(f"Waiting for upload button for {entry_name}... ({elapsed}s)")
                last_status_time = current_time
            if int(current_time - start_time) % 10 == 0 and int(current_time - start_time) > 0:
                scroll_up_multiple(2)
            time.sleep(check_interval)

    def process_single_entry(hwnd, entry_index, entry_data, images_folder):
        entry_name = f"entry_{entry_index + 1}"
        entry_json = format_entry_json(entry_data)
        if not entry_json:
            print(f"⚠️ [ENTRY] No data found for {entry_name}")
            return False

        print(f"\n{'='*50}")
        print(f"📝 [ENTRY] Processing {entry_name}/{total_entries}")
        print(f"{'='*50}")
        update_operation_status(f"Processing {entry_name}...")

        print(f"🔍 [ENTRY] Step 1: Looking for ask_gemini.png with fallback support...")
        ask_location = wait_for_image("ask_gemini.png", timeout_seconds=60, confidence=0.8)
        if not ask_location:
            print(f"❌ [ENTRY] Could not find ask_gemini.png in any variant")
            try:
                hud.print("❌ UI element not found", "error")
            except Exception:
                pass
            update_operation_status(f"Could not find ask_gemini.png for {entry_name}", is_error=True)
            return False

        pyautogui.moveTo(ask_location.x, ask_location.y, duration=0.1)
        pyautogui.click()
        time.sleep(0.3)

        print(f"📝 [ENTRY] Step 2: Pasting full entry JSON...")
        try:
            hud.print(f"📝 Pasting {entry_name}...", "typing")
        except Exception:
            pass
        pyautogui.click()
        time.sleep(0.1)
        pyautogui.hotkey('ctrl', 'a')
        time.sleep(0.05)
        pyautogui.press('backspace')
        time.sleep(0.05)
        pyperclip.copy(entry_json)
        pyautogui.hotkey('ctrl', 'v')
        time.sleep(0.3)

        print(f"🔍 [ENTRY] Step 3: Looking for upload_to_gemini.png to confirm paste...")
        upload_location = wait_for_image("upload_to_gemini.png", timeout_seconds=10, confidence=0.7)

        if not upload_location:
            print(f"⚠️ [UPLOAD] Upload button not found initially - verifying text was pasted...")
            pyautogui.click()
            time.sleep(0.1)
            pyautogui.hotkey('ctrl', 'a')
            time.sleep(0.1)
            pyautogui.hotkey('ctrl', 'c')
            time.sleep(0.1)
            clipboard_content = pyperclip.paste()
            if clipboard_content and len(clipboard_content) > 10:
                entry_check = entry_json[:100] if len(entry_json) > 100 else entry_json
                clipboard_check = clipboard_content[:100] if len(clipboard_content) > 100 else clipboard_content
                if entry_check.strip() in clipboard_check or clipboard_check.strip() in entry_check:
                    print(f"✅ [UPLOAD] Text was successfully pasted (verified by clipboard)")
                    print(f"⏳ [UPLOAD] Waiting INDEFINITELY for upload_to_gemini.png button to appear...")
                    upload_location = wait_for_upload_button_indefinitely(hwnd, entry_name)
                    if upload_location is None:
                        return False
                else:
                    print(f"⚠️ [UPLOAD] Text not properly pasted - clipboard content doesn't match")
                    print(f"🔄 [UPLOAD] Retrying paste...")
                    pyautogui.click()
                    time.sleep(0.1)
                    pyautogui.hotkey('ctrl', 'a')
                    time.sleep(0.05)
                    pyautogui.press('backspace')
                    time.sleep(0.05)
                    pyperclip.copy(entry_json)
                    pyautogui.hotkey('ctrl', 'v')
                    time.sleep(0.3)
                    print(f"⏳ [UPLOAD] Waiting INDEFINITELY for upload_to_gemini.png after retry...")
                    upload_location = wait_for_upload_button_indefinitely(hwnd, entry_name)
                    if upload_location is None:
                        return False
            else:
                print(f"❌ [UPLOAD] Paste verification failed - clipboard is empty")
                print(f"🔄 [UPLOAD] Retrying paste...")
                pyautogui.click()
                time.sleep(0.1)
                pyautogui.hotkey('ctrl', 'a')
                time.sleep(0.05)
                pyautogui.press('backspace')
                time.sleep(0.05)
                pyperclip.copy(entry_json)
                pyautogui.hotkey('ctrl', 'v')
                time.sleep(0.3)
                print(f"⏳ [UPLOAD] Waiting INDEFINITELY for upload_to_gemini.png after retry...")
                upload_location = wait_for_upload_button_indefinitely(hwnd, entry_name)
                if upload_location is None:
                    return False

        if upload_location:
            print(f"✅ [ENTRY] Found upload_to_gemini.png - clicking on RIGHT side to submit")
            variants = get_image_variants("upload_to_gemini.png")
            found_variant = False
            for variant_path in variants:
                try:
                    location = pyautogui.locateOnScreen(variant_path, confidence=0.7)
                    if location:
                        click_x = location.left + int(location.width * 0.75)
                        click_y = location.top + int(location.height / 2)
                        print(f"🎯 [ENTRY] Clicking {os.path.basename(variant_path)} right side at ({click_x}, {click_y})")
                        pyautogui.moveTo(click_x, click_y, duration=0.1)
                        pyautogui.click()
                        time.sleep(0.5)
                        found_variant = True
                        break
                except Exception:
                    pass
            if not found_variant:
                pyautogui.moveTo(upload_location.x, upload_location.y, duration=0.1)
                pyautogui.click()
                time.sleep(0.5)
            for _ in range(10):
                if not find_image("upload_to_gemini.png", confidence=0.7):
                    print(f"✅ [ENTRY] upload_to_gemini.png disappeared - request sent")
                    break
                time.sleep(0.3)
        else:
            print(f"❌ [ENTRY] Could not find upload_to_gemini.png - aborting entry")
            try:
                hud.print("❌ Upload button not found", "error")
            except Exception:
                pass
            update_operation_status(f"Could not find upload_to_gemini.png for {entry_name}", is_error=True)
            return False

        print(f"⏳ [ENTRY] Step 4: Waiting for generation to complete...")
        generation_complete, status = wait_for_generation_complete(hwnd, entry_name, max_wait_seconds=300)

        if status == "limit_reached":
            print(f"❌ [ENTRY] Generation limit reached - aborting all operations")
            abort_operation("Gemini generation limit reached")
            return False
        if not generation_complete:
            print(f"❌ [ENTRY] Generation did not complete within timeout")
            try:
                hud.print("❌ Generation timeout", "error")
            except Exception:
                pass
            update_operation_status(f"Generation timeout for {entry_name}", is_error=True)
            return False

        print(f"🔍 [ENTRY] Step 5: Checking for limit message before proceeding...")
        limit_location = find_image("gemini_generation_limit_message.png", confidence=0.7)
        if limit_location:
            print(f"❌ [ENTRY] Generation limit reached! Aborting.")
            try:
                hud.print("❌ Generation limit reached!", "error")
            except Exception:
                pass
            update_operation_status(f"Generation limit reached for {entry_name}", is_error=True)
            abort_operation(f"Generation limit reached for {entry_name}")
            return False

        print(f"🔍 [ENTRY] Step 6: Looking for more.png...")
        scroll_up_multiple(3)
        time.sleep(0.5)
        more_location = None
        for attempt in range(5):
            limit_location = find_image("gemini_generation_limit_message.png", confidence=0.7)
            if limit_location:
                print(f"❌ [ENTRY] Generation limit reached while searching for menu!")
                try:
                    hud.print("❌ Generation limit reached!", "error")
                except Exception:
                    pass
                update_operation_status(f"Generation limit reached for {entry_name}", is_error=True)
                abort_operation(f"Generation limit reached for {entry_name}")
                return False
            more_location = find_image("more.png", confidence=0.8)
            if more_location:
                break
            print(f"⏳ [ENTRY] more.png not found (attempt {attempt+1}/5) - scrolling up...")
            scroll_up_multiple(2)
            time.sleep(0.5)

        if more_location:
            variants = get_image_variants("more.png")
            found_variant = False
            for variant_path in variants:
                try:
                    location = pyautogui.locateOnScreen(variant_path, confidence=0.8)
                    if location:
                        click_x = location.left + int(location.width * 0.75)
                        click_y = location.top + int(location.height / 2)
                        print(f"🎯 [ENTRY] Clicking {os.path.basename(variant_path)} right side at ({click_x}, {click_y})")
                        pyautogui.moveTo(click_x, click_y, duration=0.1)
                        pyautogui.click()
                        time.sleep(0.5)
                        print(f"✅ [ENTRY] Clicked more.png right side")
                        found_variant = True
                        break
                except Exception:
                    pass
            if not found_variant:
                print(f"⚠️ [ENTRY] Could not click more.png - none of the variants worked")
        else:
            print(f"⚠️ [ENTRY] more.png not found after multiple attempts - skipping")

        print(f"🔍 [ENTRY] Step 7: Looking for download_gemini_image.png...")
        download_location = wait_for_image("download_gemini_image.png", timeout_seconds=30, confidence=0.8)
        if not download_location:
            scroll_up_multiple(2)
            time.sleep(0.5)
            download_location = wait_for_image("download_gemini_image.png", timeout_seconds=20, confidence=0.8)
        if not download_location:
            print(f"❌ [ENTRY] Could not find download_gemini_image.png")
            try:
                hud.print("❌ Download button not found", "error")
            except Exception:
                pass
            update_operation_status(f"Could not find download button for {entry_name}", is_error=True)
            return False

        pyautogui.moveTo(download_location.x, download_location.y, duration=0.1)
        pyautogui.click()
        time.sleep(0.5)
        print(f"✅ [ENTRY] Download initiated")
        try:
            hud.print(f"📥 Downloading {entry_name}...", "downloading")
        except Exception:
            pass
        update_operation_status(f"Downloading {entry_name}...")

        print(f"📊 [ENTRY] Step 8: Monitoring download with retry...")
        download_success = False
        file_path = None
        download_attempts = 0
        max_download_attempts = 3
        while download_attempts < max_download_attempts and not download_success:
            download_attempts += 1
            print(f"🔄 [ENTRY] Download attempt {download_attempts}/{max_download_attempts}")
            timeout_seconds = 30 if download_attempts == 1 else 20
            download_success, file_path = check_and_rename_download(
                entry_name, images_folder, timeout_seconds=timeout_seconds, check_interval=0.3
            )
            if download_success:
                print(f"✅ [ENTRY] Download successful on attempt {download_attempts}")
                break
            if download_attempts < max_download_attempts:
                print(f"⚠️ [ENTRY] Download not found after {timeout_seconds}s - retrying...")
                try:
                    hud.print(f"🔄 Retry download... ({download_attempts}/{max_download_attempts})", "warning")
                except Exception:
                    pass
                update_operation_status(f"Retrying download for {entry_name}... (attempt {download_attempts + 1})")
                retry_success = click_more_and_download(hwnd, entry_name, max_retries=3)
                if not retry_success:
                    print(f"❌ [ENTRY] Failed to initiate retry download")
                    break
                time.sleep(2)

        if download_success:
            print(f"🎉 [ENTRY] {entry_name} downloaded and saved successfully!")
            try:
                hud.print(f"✅ {entry_name} complete", "success")
            except Exception:
                pass
            update_operation_status(f"{entry_name} completed successfully", is_success=True)
            return True
        else:
            print(f"❌ [ENTRY] Failed to download {entry_name} after {max_download_attempts} attempts")
            try:
                hud.print("❌ Download failed", "error")
            except Exception:
                pass
            update_operation_status(f"Failed to download {entry_name} after multiple attempts", is_error=True)
            return False

    def main_gemini_workflow():
        try:
            if not os.path.exists(GUI_IMAGES):
                print(f"❌ Error: GUI_IMAGES path not found: {GUI_IMAGES}")
                update_operation_status(f"GUI_IMAGES path not found: {GUI_IMAGES}", is_error=True)
                return

            images_folder = os.path.join(IMAGES_PATH, project_title)
            print(f"🎬 [MAIN] Starting Gemini image generation workflow...")
            print(f"🖥️ [MAIN] GUI Images path: {GUI_IMAGES}")
            print(f"📁 [MAIN] Project: '{project_title}'")
            print(f"📁 [MAIN] Images folder: {images_folder}")
            print(f"📊 [MAIN] Total entries: {total_entries}")

            valid, start_index = validate_entry_order(images_folder)
            if not valid:
                print(f"❌ [MAIN] Entry validation failed")
                return
            if start_index >= total_entries:
                print(f"✅ [MAIN] All {total_entries} entries already processed!")
                try:
                    hud.print("✅ All entries complete!", "success")
                except Exception:
                    pass
                _set_currently_running(
                    "generate_gemini_images_successful",
                    status="pending",
                    operation_status=f"✅ All {total_entries} entries already processed"
                )
                print("🏁 [GEMINI] currently_running set to 'generate_gemini_images_successful'")
                return

            print(f"📊 [MAIN] Starting from entry {start_index + 1}")

            hwnd = ensure_window_ready_and_focused()
            print(f"🪟 [MAIN] Browser ready (HWND: {hwnd})")
            update_operation_status("Browser initialized for Gemini operation")

            print(f"🔍 [MAIN] Loading Gemini URL...")
            success, hwnd = load_gemini_url(hwnd)
            if not success:
                print(f"❌ [MAIN] Failed to load Gemini URL")
                update_operation_status("Failed to load Gemini URL", is_error=True)
                return

            print(f"🔍 [MAIN] Waiting for Gemini UI to load...")
            ask_location = wait_for_image("ask_gemini.png", timeout_seconds=60, confidence=0.8)
            if not ask_location:
                print(f"⚠️ [MAIN] ask_gemini.png not found - page may not be loaded")
                update_operation_status("Gemini UI not loaded", is_error=True)
                return
            print(f"✅ [MAIN] Gemini UI loaded successfully")

            all_ok = True
            for i in range(start_index, total_entries):
                check_for_termination()
                entry = all_entries[i]
                success = process_single_entry(hwnd, i, entry, images_folder)
                if success:
                    time.sleep(2)
                else:
                    all_ok = False
                    print(f"⚠️ [MAIN] Entry {i+1} failed - continuing with next")
                    time.sleep(3)
                    try:
                        screen_width = win32api.GetSystemMetrics(win32con.SM_CXSCREEN)
                        screen_height = win32api.GetSystemMetrics(win32con.SM_CYSCREEN)
                        pyautogui.click(screen_width // 2, screen_height // 4)
                    except Exception:
                        pass
                    if not find_image("ask_gemini.png", confidence=0.7):
                        print(f"🔍 [MAIN] ask_gemini.png not found - navigating to Gemini URL again...")
                        fast_paste_url(hwnd, GEMINI_URL)
                        time.sleep(3)
                        wait_for_image("ask_gemini.png", timeout_seconds=30)

            print(f"\n{'='*50}")
            print(f"🎉 [MAIN] All entries processed!")
            print(f"{'='*50}")
            try:
                hud.print("🎉 All entries processed!", "success")
            except Exception:
                pass

            if all_ok:
                _set_currently_running(
                    "generate_gemini_images_successful",
                    status="pending",
                    operation_status=f"✅ All {total_entries} entries processed successfully"
                )
                print("🏁 [GEMINI] currently_running set to 'generate_gemini_images_successful'")
            else:
                # Some entries failed – keep the function name so it can be retried
                _set_currently_running(
                    "generate_gemini_images()",
                    status="error",
                    operation_status="⚠️ Some entries failed during Gemini generation"
                )
                print("⚠️ [GEMINI] Some entries failed – currently_running remains 'generate_gemini_images()'")

        except KeyboardInterrupt as ki:
            update_operation_status("Gemini operation manually terminated by user", is_abort=True)
            try:
                hud.show_summary("🛑 Program Halted")
            except Exception:
                pass
            print(f"\n✅ Program successfully halted: {ki}")
        except SystemExit as se:
            print(f"🛑 System exit: {se}")
        except Exception as e:
            print(f"❌ [MAIN] Error: {e}")
            traceback.print_exc()
            error_msg = f"Unexpected error in Gemini operation: {str(e)}"
            update_operation_status(error_msg, is_error=True)
            try:
                hud.print("❌ Error occurred", "error")
            except Exception:
                pass
        finally:
            try:
                keyboard.remove_hotkey('alt+/')
                print("🧹 Cleaned up hotkey")
            except Exception:
                pass

    main_gemini_workflow()

def generate_google_flow_images():
    """
    Generates images using Google Flow via GUI automation with pyautogui image recognition.
    Features:
    - Uses GOOGLE_FLOW_URL and GUI_IMAGES path variable
    - Checks panel.json for image_generation_engine == "Google Flow" (case-insensitive)
    - If engine is not Google Flow, function exits immediately
    - Always runs when the selected image engine is Google Flow; no currently_running gate
    - On start sets currently_running to "generate_google_flow_images()"
    - On completion, verifies all scene images exist, appends the completed project
      snapshot to READY_SCRIPT, then removes project_name from PANEL_PATH
    - On any error/abort keeps currently_running as "generate_google_flow_images()"
    - Retries failed workflow runs indefinitely until manually stopped with Alt+/
    - Restores the image-hover cursor position before each download retry
    """
    import os
    import json
    import time
    import re
    import subprocess
    import traceback

    try:
        import pyautogui
        import pyperclip
        import win32api
        import win32con
        import win32gui
        import win32process
        import psutil
        import keyboard
    except ImportError as e:
        print(f"❌ [GF] Missing dependency: {e}")
        return

    pyautogui.PAUSE = 0.0

    # ------------------------------------------------------------------
    # Status helpers (self-contained)
    # ------------------------------------------------------------------
    def _load_panel():
        if not os.path.exists(PANEL_PATH):
            return {}
        try:
            with open(PANEL_PATH, 'r', encoding='utf-8') as f:
                return json.load(f)
        except Exception:
            return {}

    def _save_panel(data):
        try:
            os.makedirs(os.path.dirname(PANEL_PATH) or '.', exist_ok=True)
            with open(PANEL_PATH, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=4, ensure_ascii=False)
            return True
        except Exception as e:
            print(f"❌ [STATUS] Failed to save panel: {e}")
            return False

    def _get_currently_running():
        data = _load_panel()
        val = data.get("currently_running")
        if val is None:
            return None
        val = str(val).strip()
        if val.lower() in ("", "none", "null"):
            return None
        return val

    def _set_currently_running(value, status=None, operation_status=None):
        data = _load_panel()
        data["currently_running"] = value
        if status is not None:
            data["status"] = status
        if operation_status is not None:
            data["operation_status"] = operation_status
        return _save_panel(data)

    def _transfer_completed_project_to_ready_script(images_folder, expected_entries):
        """
        Append a completed project snapshot to READY_SCRIPT without overwriting
        existing projects. Only remove project_name from PANEL_PATH after the
        append has been successfully written and verified.
        """
        import copy

        # Confirm every expected scene has a corresponding completed image.
        image_extensions = (".png", ".jpg", ".jpeg", ".gif", ".webp", ".bmp", ".tiff")
        missing = []
        for number in range(1, expected_entries + 1):
            expected_stem = f"entry_{number}"
            found = False
            if os.path.isdir(images_folder):
                for filename in os.listdir(images_folder):
                    stem, ext = os.path.splitext(filename)
                    if stem.lower() == expected_stem.lower() and ext.lower() in image_extensions:
                        full_path = os.path.join(images_folder, filename)
                        if os.path.isfile(full_path) and os.path.getsize(full_path) > 0:
                            found = True
                            break
            if not found:
                missing.append(expected_stem)

        if missing:
            print("❌ [READY-SCRIPT] Transfer cancelled; missing image(s): " + ", ".join(missing))
            update_operation_status(
                "Project was not transferred because one or more scene images are missing",
                is_error=True
            )
            return False

        # Re-read the source so status updates made during the workflow are retained.
        try:
            with open(PANEL_PATH, "r", encoding="utf-8") as source_file:
                source_data = json.load(source_file)
            if not isinstance(source_data, dict) or "project_name" not in source_data:
                print("❌ [READY-SCRIPT] Source panel has no project_name to transfer.")
                return False

            project_snapshot = copy.deepcopy(source_data)
            project_snapshot["currently_running"] = "generate_google_flow_images_successful"
            project_snapshot["status"] = "completed"
            project_snapshot["operation_status"] = (
                f"Completed project '{project_snapshot.get('project_name', {}).get('project_title', 'Untitled')}' "
                f"with {expected_entries} verified image(s)"
            )

            ready_dir = os.path.dirname(READY_SCRIPT)
            if ready_dir:
                os.makedirs(ready_dir, exist_ok=True)

            if os.path.exists(READY_SCRIPT) and os.path.getsize(READY_SCRIPT) > 0:
                with open(READY_SCRIPT, "r", encoding="utf-8") as ready_file:
                    ready_data = json.load(ready_file)
            else:
                ready_data = {"projects": []}

            # Supported append formats: a JSON list, {"projects": [...]}, or a
            # legacy single-project object. Preserve existing metadata/projects.
            if isinstance(ready_data, list):
                ready_data.append(project_snapshot)
            elif isinstance(ready_data, dict):
                if isinstance(ready_data.get("projects"), list):
                    ready_data["projects"].append(project_snapshot)
                elif "project_name" in ready_data:
                    existing_project = ready_data
                    ready_data = {"projects": [existing_project, project_snapshot]}
                else:
                    ready_data["projects"] = [project_snapshot]
            else:
                raise ValueError("ready_script.json must contain a JSON object or list")

            with open(READY_SCRIPT, "w", encoding="utf-8") as ready_file:
                json.dump(ready_data, ready_file, indent=4, ensure_ascii=False)
                ready_file.flush()
                os.fsync(ready_file.fileno())

            # Verify the appended project can be read back before touching source.
            with open(READY_SCRIPT, "r", encoding="utf-8") as verify_file:
                verified_data = json.load(verify_file)
            if isinstance(verified_data, list):
                verified = any(
                    isinstance(item, dict)
                    and item.get("project_name", {}).get("project_title")
                        == project_snapshot.get("project_name", {}).get("project_title")
                    for item in verified_data
                )
            else:
                verified = any(
                    isinstance(item, dict)
                    and item.get("project_name", {}).get("project_title")
                        == project_snapshot.get("project_name", {}).get("project_title")
                    for item in verified_data.get("projects", [])
                )
            if not verified:
                raise IOError("Could not verify the project in ready_script.json")

            # Remove only the completed project payload; retain engine/status settings.
            source_data.pop("project_name", None)
            source_data["currently_running"] = "generate_google_flow_images_successful"
            source_data["status"] = "completed"
            source_data["operation_status"] = (
                f"Project transferred to ready script: "
                f"{project_snapshot.get('project_name', {}).get('project_title', 'Untitled')}"
            )
            with open(PANEL_PATH, "w", encoding="utf-8") as source_file:
                json.dump(source_data, source_file, indent=4, ensure_ascii=False)

            print(
                f"✅ [READY-SCRIPT] Appended completed project "
                f"'{project_snapshot.get('project_name', {}).get('project_title', 'Untitled')}' "
                f"to {READY_SCRIPT}; removed project_name from {PANEL_PATH}"
            )
            return True

        except Exception as exc:
            print(f"❌ [READY-SCRIPT] Failed to transfer completed project: {exc}")
            traceback.print_exc()
            update_operation_status(f"Failed to transfer completed project: {exc}", is_error=True)
            return False

    # ------------------------------------------------------------------
    # Engine check (no currently_running gate)
    # ------------------------------------------------------------------
    if not os.path.exists(PANEL_PATH):
        print(f"❌ Error: panel.json missing at: {PANEL_PATH}")
        try:
            hud.print("Configuration file is missing.", "error")
        except Exception:
            pass
        return

    try:
        with open(PANEL_PATH, 'r', encoding='utf-8') as file:
            panel_data = json.load(file)

        engines = panel_data.get('engines', {})
        image_generation_engine = engines.get('image_generation_engine', '').strip()

        if image_generation_engine.lower() != 'google flow':
            print(f"ℹ️ [ENGINE] Image generation engine is '{image_generation_engine}', not 'Google Flow' - skipping operation")
            print(f"   To use this function, set 'image_generation_engine' to 'Google Flow' in panel.json")
            try:
                hud.print("Google Flow is not the selected engine — skipping.", "waiting")
            except Exception:
                pass
            return

        print(f"✅ [ENGINE] Image generation engine is 'Google Flow' - proceeding with operation")

    except json.JSONDecodeError as e:
        print(f"❌ Error: panel.json is not valid JSON: {e}")
        try:
            hud.print("Configuration file appears to be corrupted.", "error")
        except Exception:
            pass
        return
    except Exception as e:
        print(f"❌ Error reading panel.json: {e}")
        try:
            hud.print("Could not read the configuration file.", "error")
        except Exception:
            pass
        return

    # Claim the slot
    _set_currently_running(
        "generate_google_flow_images()",
        status="pending",
        operation_status="Running generate_google_flow_images()"
    )
    print("🔒 [GF] Claimed currently_running = 'generate_google_flow_images()'")

    # ------------------------------------------------------------------
    # Rest of original logic
    # ------------------------------------------------------------------
    project_title = panel_data.get('project_name', {}).get('project_title', 'workproject1')
    script_json = panel_data.get('project_name', {}).get('script_json', {})
    script_data = script_json.get('script', {})

    # Support the new scene-by-scene structure:
    # {
    #   "characters_prompt": [...],
    #   "script": [
    #       {
    #           "shot_number": 1,
    #           "characters_reference": {...},
    #           "scene_Image_generation": "...",
    #           ...
    #       }
    #   ]
    # }
    # Also retain compatibility with the previous nested batches/entries format.
    all_entries = []

    def _is_new_scene_entry(item):
        return (
            isinstance(item, dict)
            and "characters_reference" in item
            and "scene_Image_generation" in item
        )

    def _collect_scene_entries(node):
        if isinstance(node, list):
            for item in node:
                if _is_new_scene_entry(item):
                    all_entries.append(item)
                elif isinstance(item, (dict, list)):
                    _collect_scene_entries(item)
        elif isinstance(node, dict):
            # A direct scene object should be collected once.
            if _is_new_scene_entry(node):
                all_entries.append(node)
                return
            # Old format: batches containing an "entries" list.
            entries = node.get("entries")
            if isinstance(entries, list):
                for entry in entries:
                    if isinstance(entry, dict) and (
                        _is_new_scene_entry(entry) or "scene_pov_prompt" in entry
                    ):
                        all_entries.append(entry)
                # Continue through other branches too, in case the structure mixes formats.
            for key, value in node.items():
                if key not in ("characters_prompt", "entries"):
                    if isinstance(value, (dict, list)):
                        _collect_scene_entries(value)

    _collect_scene_entries(script_data)

    total_entries = len(all_entries)
    print(f"📊 [CONFIG] Found {total_entries} total entries")

    if total_entries == 0:
        print("❌ No entries found to process")
        try:
            hud.print("No scenes were found to work on.", "error")
        except Exception:
            pass
        _set_currently_running("generate_google_flow_images()", status="error",
                               operation_status="❌ No entries found to process")
        return

    terminate_automation = False
    operation_status_flag = True
    operation_status_message = ""
    operation_aborted = False

    # Only these two fields are sent to Google Flow for each new-format scene.
    # Timing, dialogue, shot_number, and all other metadata are intentionally excluded.
    SCENE_PROMPT_FIELDS = ("characters_reference", "scene_Image_generation")
    FIELDS_TO_STRIP = {"start", "stop", "duration", "audio_focus"}
    SEND_LOCATION_TOLERANCE = 20

    def update_operation_status(message, is_error=False, is_abort=False, is_success=False):
        nonlocal operation_status_message, operation_status_flag, operation_aborted
        try:
            with open(PANEL_PATH, 'r', encoding='utf-8') as file:
                current_panel = json.load(file)

            if is_abort:
                operation_status_message = f"❌ ABORTED: {message}"
                operation_status_flag = False
                operation_aborted = True
            elif is_error:
                operation_status_message = f"⚠️ ERROR: {message}"
                operation_status_flag = False
            elif is_success:
                operation_status_message = f"✅ {message}"
                operation_status_flag = True
            else:
                operation_status_message = f"ℹ️ {message}"

            current_panel['operation_status'] = operation_status_message
            if is_error or is_abort:
                current_panel['currently_running'] = "generate_google_flow_images()"
                current_panel['status'] = "error" if is_error else "aborted"
            elif is_success:
                current_panel['currently_running'] = "generate_google_flow_images()"
                current_panel['status'] = "pending"

            with open(PANEL_PATH, 'w', encoding='utf-8') as file:
                json.dump(current_panel, file, indent=4, ensure_ascii=False)

            if is_abort:
                print(f"🛑 [STATUS] Operation aborted: {message}")
                raise SystemExit(f"Operation aborted: {message}")
        except SystemExit:
            raise
        except Exception as e:
            print(f"⚠️ [STATUS] Failed to update operation status: {e}")

    def abort_operation(reason):
        print(f"🛑 [ABORT] Aborting operation: {reason}")
        update_operation_status(f"Aborting Google Flow image generation: {reason}", is_abort=True)

    def check_operation_status():
        if not operation_status_flag or operation_aborted:
            print("🛑 [STATUS] Operation status is invalid - aborting")
            update_operation_status("Operation status invalid - aborting Google Flow operation", is_abort=True)
            return False
        return True

    def on_terminate_shortcut():
        nonlocal terminate_automation
        try:
            hud.print("Stopped at your request.", "warning")
        except Exception:
            pass
        print("🛑 Manual Stop Triggered!")
        terminate_automation = True
        update_operation_status("Google Flow operation manually terminated by user (Alt+/)", is_abort=True)


    def check_for_termination():
        if terminate_automation:
            update_operation_status("Google Flow operation terminated by user", is_abort=True)
            raise KeyboardInterrupt("User forced exit via shortcut key.")
        if not check_operation_status():
            raise SystemExit("Operation status invalid")

    def format_entry_for_paste(entry_data):
        """Serialize only the two approved prompt fields for the new scene schema."""
        try:
            if not isinstance(entry_data, dict):
                print("⚠️ [PREPARE] Scene entry is not a JSON object.")
                return ""

            # New structure: copy only characters_reference and scene_Image_generation.
            if (
                "characters_reference" in entry_data
                or "scene_Image_generation" in entry_data
            ):
                cleaned = {
                    key: entry_data[key]
                    for key in SCENE_PROMPT_FIELDS
                    if key in entry_data
                }

                missing = [
                    key for key in SCENE_PROMPT_FIELDS
                    if key not in cleaned
                ]
                if missing:
                    print(
                        "⚠️ [PREPARE] New-format scene is missing required field(s): "
                        + ", ".join(missing)
                    )
                    return ""

                return json.dumps(cleaned, indent=4, ensure_ascii=False)

            # Legacy fallback: keep the former scene prompt behaviour for old entries,
            # while still stripping timing/audio metadata.
            cleaned = {
                key: value
                for key, value in entry_data.items()
                if key not in FIELDS_TO_STRIP
            }
            return json.dumps(cleaned, indent=4, ensure_ascii=False)
        except Exception as e:
            print(f"⚠️ [PREPARE] Error preparing scene data: {e}")
            return ""

    # --- DYNAMIC FALLBACK HELPERS ---
    def get_image_variants(base_name):
        variants = []
        base_name_without_ext = os.path.splitext(base_name)[0]
        ext = os.path.splitext(base_name)[1]
        original_path = os.path.join(GUI_IMAGES, base_name)
        if os.path.exists(original_path):
            variants.append(original_path)
        pattern = re.compile(rf'^{re.escape(base_name_without_ext)}(\d+)\.{re.escape(ext[1:])}$', re.IGNORECASE)
        try:
            files = os.listdir(GUI_IMAGES)
            numbered_variants = []
            for filename in files:
                match = pattern.match(filename)
                if match:
                    number = int(match.group(1))
                    full_path = os.path.join(GUI_IMAGES, filename)
                    numbered_variants.append((number, full_path))
            numbered_variants.sort(key=lambda x: x[0])
            for _, full_path in numbered_variants:
                variants.append(full_path)
        except Exception as e:
            print(f"⚠️ [FALLBACK] Error scanning for variants of {base_name}: {e}")
        return variants

    def find_image_with_fallback(image_name, confidence=0.8, region=None):
        variants = get_image_variants(image_name)
        if not variants:
            print(f"❌ [FALLBACK] No variants found for {image_name}")
            return None, None
        for variant_path in variants:
            variant_name = os.path.basename(variant_path)
            try:
                location = pyautogui.locateCenterOnScreen(variant_path, confidence=confidence, grayscale=False)
                if location:
                    print(f"✅ [FALLBACK] Found {variant_name} at ({location.x}, {location.y})")
                    return location, variant_path
            except Exception:
                pass
        return None, None

    def wait_for_image_with_fallback(image_name, timeout_seconds=60, confidence=0.8, region=None):
        variants = get_image_variants(image_name)
        if not variants:
            print(f"❌ [FALLBACK] No variants found for {image_name}")
            return None, None
        print(f"🔍 [FALLBACK] Waiting for {image_name} with {len(variants)} variant(s)...")
        start_time = time.time()
        last_status_time = 0
        while time.time() - start_time < timeout_seconds:
            check_for_termination()
            for variant_path in variants:
                variant_name = os.path.basename(variant_path)
                try:
                    location = pyautogui.locateCenterOnScreen(variant_path, confidence=confidence, grayscale=False)
                    if location:
                        print(f"✅ [FALLBACK] Found {variant_name} at ({location.x}, {location.y})")
                        return location, variant_path
                except Exception:
                    pass
            current_time = time.time()
            if current_time - last_status_time > 5:
                elapsed = int(current_time - start_time)
                print(f"⏳ [FALLBACK] Still waiting for {image_name}... ({elapsed}s elapsed)")
                last_status_time = current_time
            time.sleep(0.3)
        print(f"❌ [FALLBACK] {image_name} not found in any variant within {timeout_seconds} seconds")
        return None, None

    def wait_for_image_indefinitely(image_name, confidence=0.8, region=None, timeout_seconds=300):
        print(f"🔍 [FALLBACK] Waiting for {image_name} (timeout: {timeout_seconds}s)...")
        start_time = time.time()
        last_status_time = 0
        last_variant_count = -1
        warning_shown = False
        while time.time() - start_time < timeout_seconds:
            check_for_termination()
            variants = get_image_variants(image_name)
            if len(variants) != last_variant_count:
                print(f"🔍 [FALLBACK] {image_name}: {len(variants)} variant(s) available")
                last_variant_count = len(variants)
            if not variants:
                if not warning_shown:
                    print(f"⚠️ [FALLBACK] No variants found for {image_name} yet — will keep checking folder...")
                    warning_shown = True
                current_time = time.time()
                if current_time - last_status_time > 10:
                    elapsed = int(current_time - start_time)
                    print(f"⏳ [FALLBACK] Still waiting for {image_name} (file not found in gui_images)... ({elapsed}s elapsed)")
                    try:
                        hud.print(f"Still getting the page ready... ({elapsed}s)", "waiting")
                    except Exception:
                        pass
                    last_status_time = current_time
                time.sleep(1.0)
                continue
            for variant_path in variants:
                variant_name = os.path.basename(variant_path)
                try:
                    location = pyautogui.locateCenterOnScreen(variant_path, confidence=confidence, grayscale=False)
                    if location:
                        print(f"✅ [FALLBACK] Found {variant_name} at ({location.x}, {location.y})")
                        return location
                except Exception:
                    pass
            current_time = time.time()
            if current_time - last_status_time > 10:
                elapsed = int(current_time - start_time)
                print(f"⏳ [FALLBACK] Still waiting for {image_name} on screen... ({elapsed}s elapsed)")
                try:
                    hud.print(f"Still getting the page ready... ({elapsed}s)", "waiting")
                except Exception:
                    pass
                last_status_time = current_time
            time.sleep(0.3)
        elapsed = int(time.time() - start_time)
        print(f"⏰ [FALLBACK] Timed out waiting for {image_name} after {elapsed}s.")
        return None

    def find_image_at_location(image_name, target_x, target_y, tolerance=20, confidence=0.7):
        variants = get_image_variants(image_name)
        if not variants:
            return None
        for variant_path in variants:
            try:
                location = pyautogui.locateCenterOnScreen(variant_path, confidence=confidence, grayscale=False)
                if location:
                    dx = abs(location.x - target_x)
                    dy = abs(location.y - target_y)
                    if dx <= tolerance and dy <= tolerance:
                        print(f"✅ [LOC-LOCK] {os.path.basename(variant_path)} matched at "
                              f"({location.x}, {location.y}) — within tolerance of "
                              f"({target_x}, {target_y}) [dx={dx}, dy={dy}]")
                        return location
                    else:
                        print(f"⚠️ [LOC-LOCK] {os.path.basename(variant_path)} matched at "
                              f"({location.x}, {location.y}) BUT outside tolerance of "
                              f"({target_x}, {target_y}) [dx={dx}, dy={dy}] — rejecting this match")
            except Exception:
                pass
        return None

    # --- WINDOW MANAGEMENT HELPERS ---
    def get_current_monitor():
        try:
            cursor_pos = win32api.GetCursorPos()
            monitor_info = win32api.GetMonitorInfo(win32api.MonitorFromPoint(cursor_pos))
            return monitor_info['Monitor']
        except Exception:
            return (0, 0, win32api.GetSystemMetrics(win32con.SM_CXSCREEN),
                    win32api.GetSystemMetrics(win32con.SM_CYSCREEN))

    def get_edge_window_on_monitor(monitor_bounds):
        monitor_left, monitor_top, monitor_right, monitor_bottom = monitor_bounds
        edge_windows = []
        edge_process_names = ["msedge.exe"]

        def enum_windows_callback(hwnd, windows):
            if win32gui.IsWindowVisible(hwnd):
                try:
                    _, pid = win32process.GetWindowThreadProcessId(hwnd)
                    process = psutil.Process(pid)
                    if process.name().lower() in edge_process_names:
                        rect = win32gui.GetWindowRect(hwnd)
                        left, top, right, bottom = rect
                        width, height = right - left, bottom - top
                        if width > 200 and height > 200:
                            window_center_x = (left + right) / 2
                            window_center_y = (top + bottom) / 2
                            is_on_current_monitor = (
                                monitor_left <= window_center_x <= monitor_right and
                                monitor_top <= window_center_y <= monitor_bottom
                            )
                            if is_on_current_monitor:
                                windows.append({'hwnd': hwnd, 'width': width, 'height': height})
                except Exception:
                    pass
            return True

        win32gui.EnumWindows(enum_windows_callback, edge_windows)
        edge_windows.sort(key=lambda w: w['width'] * w['height'], reverse=True)
        return edge_windows

    def ensure_edge_window_ready():
        check_for_termination()
        current_monitor = get_current_monitor()
        edge_windows = get_edge_window_on_monitor(current_monitor)

        if edge_windows:
            hwnd = edge_windows[0]['hwnd']
            print(f"🪟 [WINDOW] Found existing Edge window handle: {hwnd}")
            try:
                if win32gui.IsIconic(hwnd):
                    print("🔄 [WINDOW] Window was minimized, restoring...")
                    win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                    time.sleep(0.3)
                print("🔄 [WINDOW] Maximizing window...")
                win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                time.sleep(0.5)
                win32gui.SetForegroundWindow(hwnd)
                time.sleep(0.2)
                print("✅ [WINDOW] Window ready - maximized and focused")
                update_operation_status("Browser window ready for Google Flow operation")
                return hwnd
            except Exception as e:
                print(f"⚠️ [WINDOW] Error preparing existing window: {e}")

        print("💻 [WINDOW] No Edge window found, launching new instance...")
        update_operation_status("Launching browser for Google Flow operation...")
        try:
            hud.print("Opening the browser...", "navigating")
        except Exception:
            pass
        subprocess.Popen([edge_path, "about:blank"])

        for attempt in range(20):
            check_for_termination()
            time.sleep(0.5)
            edge_windows = get_edge_window_on_monitor(current_monitor)
            if edge_windows:
                hwnd = edge_windows[0]['hwnd']
                print(f"🪟 [WINDOW] New Edge window launched, handle: {hwnd}")
                try:
                    if win32gui.IsIconic(hwnd):
                        win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                        time.sleep(0.3)
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.5)
                    win32gui.SetForegroundWindow(hwnd)
                    time.sleep(0.2)
                    print("✅ [WINDOW] New window ready - maximized and focused")
                    update_operation_status("Browser launched for Google Flow operation")
                    return hwnd
                except Exception as e:
                    print(f"⚠️ [WINDOW] Error preparing new window: {e}")
                    continue

        error_msg = "Failed to get or launch Edge window for Google Flow operation"
        update_operation_status(error_msg, is_error=True)
        try:
            hud.print("Could not open the browser window.", "error")
        except Exception:
            pass
        abort_operation(error_msg)
        raise RuntimeError(error_msg)

    def enforce_window_focus(hwnd):
        check_for_termination()
        try:
            if not win32gui.IsWindow(hwnd):
                print("⚠️ [FOCUS] Window handle invalid, reacquiring...")
                return False
            if win32gui.IsIconic(hwnd):
                print("🔄 [FOCUS] Window was minimized, restoring...")
                win32gui.ShowWindow(hwnd, win32con.SW_RESTORE)
                time.sleep(0.3)
            current_foreground = win32gui.GetForegroundWindow()
            if current_foreground != hwnd:
                print("🛡️ [FOCUS] Correcting window focus...")
                win32gui.SetForegroundWindow(hwnd)
                time.sleep(0.15)
            try:
                placement = win32gui.GetWindowPlacement(hwnd)
                if placement[1] != win32con.SW_SHOWMAXIMIZED:
                    print("🔄 [FOCUS] Window not maximized, maximizing...")
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.3)
            except Exception:
                try:
                    win32gui.ShowWindow(hwnd, win32con.SW_MAXIMIZE)
                    time.sleep(0.3)
                except Exception:
                    pass
            return True
        except Exception as e:
            print(f"⚠️ [FOCUS] Focus correction exception: {e}")
            return False

    def ensure_window_ready_and_focused():
        check_for_termination()
        hwnd = ensure_edge_window_ready()
        enforce_window_focus(hwnd)
        return hwnd

    def fast_paste_url(hwnd, url):
        check_for_termination()
        try:
            hud.print("Opening Google Flow...", "navigating")
        except Exception:
            pass
        print(f"📋 Pasting URL: {url}")
        pyperclip.copy(url)
        enforce_window_focus(hwnd)
        pyautogui.hotkey('ctrl', 'l')
        time.sleep(0.1)
        enforce_window_focus(hwnd)
        pyautogui.hotkey('ctrl', 'v')
        pyautogui.press('enter')
        update_operation_status("Navigating to Google Flow URL...")

    def wait_for_image(image_name, timeout_seconds=60, confidence=0.8, region=None):
        location, _ = wait_for_image_with_fallback(image_name, timeout_seconds, confidence, region)
        return location

    def find_image(image_name, confidence=0.8, region=None):
        location, _ = find_image_with_fallback(image_name, confidence, region)
        return location

    def move_mouse_to_center_of_screen():
        try:
            screen_width = win32api.GetSystemMetrics(win32con.SM_CXSCREEN)
            screen_height = win32api.GetSystemMetrics(win32con.SM_CYSCREEN)
            center_x = screen_width // 2
            center_y = screen_height // 2
            pyautogui.moveTo(center_x, center_y, duration=0.15)
            print(f"🖱️ [MOUSE] Moved cursor to screen center ({center_x}, {center_y})")
        except Exception as e:
            print(f"⚠️ [MOUSE] Could not move cursor to center: {e}")

    def move_cursor_below_search(search_location, offset_px=100):
        target_x = search_location.x
        target_y = search_location.y + offset_px
        print(f"🖱️ [CURSOR] Moving cursor {offset_px}px BELOW searchgfimage.png (same column) to ({target_x}, {target_y})")
        pyautogui.moveTo(target_x, target_y, duration=0.2)
        time.sleep(0.3)
        return target_x, target_y

    def click_right_edge_of_image(image_name, confidence=0.7):
        variants = get_image_variants(image_name)
        for variant_path in variants:
            try:
                location = pyautogui.locateOnScreen(variant_path, confidence=confidence)
                if location:
                    click_x = location.left + int(location.width * 0.95)
                    click_y = location.top + int(location.height / 2)
                    print(f"🎯 [RIGHT-EDGE] Clicking {os.path.basename(variant_path)} right edge at ({click_x}, {click_y})")
                    pyautogui.moveTo(click_x, click_y, duration=0.1)
                    pyautogui.click()
                    time.sleep(0.5)
                    return True
            except Exception:
                pass
        return False

    def scroll_down_multiple(times=3):
        for i in range(times):
            pyautogui.scroll(500)
            time.sleep(0.3)
            print(f"⬇️ [SCROLL] Scrolled down ({i+1}/{times}) at current cursor position")
        time.sleep(0.5)

    # --- GOOGLE FLOW SPECIFIC HELPERS (identical to original, status-aware) ---
    def wait_for_new_project_and_click(hwnd, timeout_seconds=120):
        print(f"🔍 [GF] Waiting for new_project.png...")
        try:
            hud.print("Looking for the new project option...", "waiting")
        except Exception:
            pass
        update_operation_status("Waiting for new project button...")
        location = wait_for_image_indefinitely("new_project.png", confidence=0.8, timeout_seconds=timeout_seconds)
        if location:
            print(f"✅ [GF] Found new_project.png, clicking...")
            pyautogui.moveTo(location.x, location.y, duration=0.1)
            pyautogui.click()
            time.sleep(1)
            try:
                hud.print("Starting a fresh workspace...", "success")
            except Exception:
                pass
            update_operation_status("Clicked new project button")
            return True
        return False

    def wait_for_gfmedia_and_confirm(hwnd, timeout_seconds=120):
        print(f"🔍 [GF] Waiting indefinitely for gfmedia.png to confirm page load...")
        print(f"   💡 Tip: If gfmedia.png is missing from gui_images folder, add it and it will be picked up automatically.")
        try:
            hud.print("Waiting for the workspace to finish loading...", "waiting")
        except Exception:
            pass
        update_operation_status("Waiting for page to load (gfmedia)...")
        location = wait_for_image_indefinitely("gfmedia.png", confidence=0.8, timeout_seconds=timeout_seconds)
        if location:
            print(f"✅ [GF] Found gfmedia.png - page loaded successfully!")
            try:
                hud.print("Workspace is ready.", "success")
            except Exception:
                pass
            update_operation_status("Page loaded - gfmedia confirmed")
            return True
        print(f"⚠️ [GF] gfmedia.png wait was terminated before finding it")
        return False

    def find_whatdoyouwantocreate_with_fallbacks(hwnd, timeout_seconds=30):
        print(f"🔍 [GF] Searching for whatdoyouwantocreate.png...")
        print(f"🔍 [GF] Step 1: Looking for expandgfsidepanel.png first...")
        try:
            hud.print("Getting the prompt area ready...", "waiting")
        except Exception:
            pass
        update_operation_status("Looking for expand side panel button...")

        expand_variants = get_image_variants("expandgfsidepanel.png")
        if expand_variants:
            expand_panel_location = find_image("expandgfsidepanel.png", confidence=0.8)
            if expand_panel_location:
                print(f"✅ [GF] Found expandgfsidepanel.png - clicking it...")
                try:
                    hud.print("Opening the side panel...", "warning")
                except Exception:
                    pass
                update_operation_status("Clicking expand side panel button")
                pyautogui.moveTo(expand_panel_location.x, expand_panel_location.y, duration=0.1)
                pyautogui.click()
                time.sleep(1)
                print(f"🔍 [GF] Now looking for whatdoyouwantocreate.png after expanding panel...")
                location = wait_for_image("whatdoyouwantocreate.png", timeout_seconds=timeout_seconds, confidence=0.8)
                if location:
                    print(f"✅ [GF] Found whatdoyouwantocreate.png after expandgfsidepanel!")
                    return location
                else:
                    print(f"⚠️ [GF] whatdoyouwantocreate.png still not found after expanding panel")
            else:
                print(f"⚠️ [GF] expandgfsidepanel.png not currently visible on screen — will try direct whatdoyouwantocreate lookup")
        else:
            print(f"⚠️ [GF] No expandgfsidepanel.png variants exist in gui_images — falling back to direct lookup")

        print(f"🔍 [GF] Step 2: Direct lookup for whatdoyouwantocreate.png...")
        try:
            hud.print("Looking for the prompt area...", "waiting")
        except Exception:
            pass
        update_operation_status("Looking for whatdoyouwantocreate input field...")
        location = wait_for_image("whatdoyouwantocreate.png", timeout_seconds=timeout_seconds, confidence=0.8)
        if location:
            print(f"✅ [GF] Found whatdoyouwantocreate.png on direct lookup!")
            return location
        print(f"❌ [GF] Could not find whatdoyouwantocreate.png after all attempts")
        return None

    def check_for_tryagain_and_abort():
        tryagain_location = find_image("tryagain.png", confidence=0.7)
        if tryagain_location:
            print(f"❌ [NETWORK] Found tryagain.png — poor network detected!")
            try:
                hud.print("Your internet connection seems unstable — stopping now.", "error")
            except Exception:
                pass
            update_operation_status(
                "Poor network detected (tryagain.png found) — aborting Google Flow operation",
                is_abort=True
            )
            abort_operation("Poor network detected (tryagain.png found)")
            return True
        return False

    def check_and_click_done_editing():
        clicked = click_right_edge_of_image("done_editinggfimage.png", confidence=0.7)
        if clicked:
            print(f"✅ [DONE-EDITING] Clicked done_editinggfimage.png right edge — resuming normal checks")
            return True
        return False

    def handle_exit_gfsidebar_and_recheck():
        print(f"🔍 [GF] no_active_gf_generation.png not found — looking for exit_gfsidebar.png fallback...")
        exit_sidebar_location = find_image("exit_gfsidebar.png", confidence=0.7)
        if exit_sidebar_location:
            print(f"✅ [GF] Found exit_gfsidebar.png — clicking right edge...")
            variants = get_image_variants("exit_gfsidebar.png")
            clicked = False
            for variant_path in variants:
                try:
                    location = pyautogui.locateOnScreen(variant_path, confidence=0.7)
                    if location:
                        click_x = location.left + int(location.width * 0.95)
                        click_y = location.top + int(location.height / 2)
                        print(f"🎯 [GF] Clicking {os.path.basename(variant_path)} right edge at ({click_x}, {click_y})")
                        pyautogui.moveTo(click_x, click_y, duration=0.1)
                        pyautogui.click()
                        time.sleep(0.8)
                        clicked = True
                        break
                except Exception:
                    pass
            if not clicked:
                pyautogui.moveTo(exit_sidebar_location.x, exit_sidebar_location.y, duration=0.1)
                pyautogui.click()
                time.sleep(0.8)
            print(f"🔄 [GF] Re-checking for no_active_gf_generation.png after exit_gfsidebar click...")
            recheck_location = find_image("no_active_gf_generation.png", confidence=0.7)
            if recheck_location:
                print(f"✅ [GF] Found no_active_gf_generation.png after exit_gfsidebar fallback!")
                return recheck_location
            else:
                print(f"⚠️ [GF] no_active_gf_generation.png still not found after exit_gfsidebar click")
        else:
            print(f"⚠️ [GF] exit_gfsidebar.png not found either")
        return None

    def paste_prompt_and_click_send(hwnd, entry_json, entry_name):
        print(f"🔍 [SEND-STEP] Looking for whatdoyouwantocreate.png...")
        location = find_whatdoyouwantocreate_with_fallbacks(hwnd, timeout_seconds=30)
        if not location:
            print(f"❌ [SEND-STEP] Could not find the prompt input area")
            try:
                hud.print("Could not find the prompt area.", "error")
            except Exception:
                pass
            update_operation_status(f"Could not find input field for {entry_name}", is_error=True)
            return None

        pyautogui.moveTo(location.x, location.y, duration=0.1)
        pyautogui.click()
        time.sleep(0.3)

        print(f"📝 [SEND-STEP] Pasting scene details...")
        try:
            hud.print(f"Preparing {entry_name}...", "typing")
        except Exception:
            pass
        pyautogui.click()
        time.sleep(0.1)
        pyautogui.hotkey('ctrl', 'a')
        time.sleep(0.05)
        pyautogui.press('backspace')
        time.sleep(0.05)
        pyperclip.copy(entry_json)
        pyautogui.hotkey('ctrl', 'v')
        time.sleep(0.3)

        print(f"🔍 [SEND-STEP] Looking for sendgf.png (initial click)...")
        send_location = wait_for_image("sendgf.png", timeout_seconds=10, confidence=0.8)
        if not send_location:
            print(f"⚠️ [SEND-STEP] Send button not found - pressing Enter instead...")
            pyautogui.press('enter')
            time.sleep(0.5)
            move_mouse_to_center_of_screen()
            return (None, None)

        send_x, send_y = int(send_location.x), int(send_location.y)
        print(f"🎯 [SEND-STEP] RECORDED sendgf location: ({send_x}, {send_y})")

        print(f"🖱️ [SEND-STEP] Moving arrow away from send region before clicking...")
        move_mouse_to_center_of_screen()
        time.sleep(0.15)
        pyautogui.click(send_x, send_y)
        time.sleep(0.4)
        print(f"✅ [SEND-STEP] Initial click on sendgf.png sent at ({send_x}, {send_y})")
        print(f"🖱️ [SEND-STEP] Moving arrow away from send region after clicking...")
        move_mouse_to_center_of_screen()
        return (send_x, send_y)

    def confirm_sendgf_and_wait_for_generating(hwnd, entry_name, entry_json=None,
                                               send_x=None, send_y=None,
                                               max_confirm_seconds=30, check_interval=0.4):
        """
        CONFIRMATION RULE:
        - First try gfpromptsent.png and its numbered variants.
        - If gfpromptsent is not visible, use imagerenderingprogress.png and its
          numbered variants as an alternative confirmation that the prompt was sent.
        - Do not use sendgf.png or no_active_gf_generation.png as prompt-sent
          confirmation signals.
        - If neither confirmation image appears within the confirmation window,
          return needs_restart so the existing outer send loop can retry.
        """

        print(
            f"🔎 [SEND-CONFIRM] Confirming prompt for {entry_name}: "
            f"checking gfpromptsent first, with imagerenderingprogress as fallback..."
        )

        if send_x is not None and send_y is not None:
            print(
                f"   ℹ️ Initial send location was ({send_x}, {send_y}); "
                f"confirmation will use on-screen status images."
            )

        try:
            hud.print("Confirming the prompt was sent...", "waiting")
        except Exception:
            pass

        update_operation_status(
            f"Confirming prompt submission for {entry_name} "
            f"(gfpromptsent, with image-rendering fallback)..."
        )

        prompt_sent_variants = get_image_variants("gfpromptsent.png")
        rendering_variants = get_image_variants("imagerenderingprogress.png")

        if prompt_sent_variants:
            print(
                f"🔍 [SEND-CONFIRM] Found {len(prompt_sent_variants)} "
                f"gfpromptsent variant(s)."
            )
            for variant_path in prompt_sent_variants:
                print(f"   • {os.path.basename(variant_path)}")
        else:
            print(
                "⚠️ [SEND-CONFIRM] No gfpromptsent variants found in gui_images; "
                "will rely on imagerenderingprogress if it appears."
            )

        if rendering_variants:
            print(
                f"🔍 [SEND-CONFIRM] Found {len(rendering_variants)} "
                f"imagerenderingprogress variant(s) available as fallback."
            )
            for variant_path in rendering_variants:
                print(f"   • {os.path.basename(variant_path)}")
        else:
            print(
                "⚠️ [SEND-CONFIRM] No imagerenderingprogress variants currently "
                "exist in gui_images."
            )

        start_time = time.time()
        last_status_time = start_time

        while time.time() - start_time < max_confirm_seconds:
            check_for_termination()
            enforce_window_focus(hwnd)

            if check_for_tryagain_and_abort():
                return "poor_network"

            check_and_click_done_editing()

            # Primary confirmation: prompt-sent image.
            prompt_sent_location, matched_prompt_variant = find_image_with_fallback(
                "gfpromptsent.png",
                confidence=0.8
            )

            if prompt_sent_location:
                matched_name = (
                    os.path.basename(matched_prompt_variant)
                    if matched_prompt_variant
                    else "gfpromptsent variant"
                )
                print(
                    f"✅ [SEND-CONFIRM] {matched_name} found at "
                    f"({prompt_sent_location.x}, {prompt_sent_location.y})."
                )
                print(
                    f"✅ [SEND-CONFIRM] Prompt CONFIRMED SENT using "
                    f"gfpromptsent detection."
                )

                try:
                    hud.print("Prompt sent.", "success")
                except Exception:
                    pass

                update_operation_status(
                    f"Prompt confirmed for {entry_name} (gfpromptsent detected)"
                )
                return "confirmed"

            # Fallback confirmation: rendering progress means Flow has started
            # processing the submitted prompt, even if gfpromptsent is absent.
            rendering_location, matched_rendering_variant = find_image_with_fallback(
                "imagerenderingprogress.png",
                confidence=0.7
            )

            if rendering_location:
                matched_name = (
                    os.path.basename(matched_rendering_variant)
                    if matched_rendering_variant
                    else "imagerenderingprogress variant"
                )
                print(
                    f"✅ [SEND-CONFIRM] gfpromptsent was not found, but "
                    f"{matched_name} was detected at "
                    f"({rendering_location.x}, {rendering_location.y})."
                )
                print(
                    f"✅ [SEND-CONFIRM] Prompt CONFIRMED SENT using "
                    f"image-rendering progress fallback."
                )

                try:
                    hud.print("Prompt sent — image rendering has started.", "success")
                except Exception:
                    pass

                update_operation_status(
                    f"Prompt confirmed for {entry_name} "
                    f"(imagerenderingprogress fallback detected)"
                )
                return "confirmed"

            current_time = time.time()
            if current_time - last_status_time > 5:
                elapsed = int(current_time - start_time)
                print(
                    f"⏳ [SEND-CONFIRM] Waiting for gfpromptsent or "
                    f"imagerenderingprogress confirmation... ({elapsed}s elapsed)"
                )
                last_status_time = current_time

            time.sleep(check_interval)

        print(
            f"⚠️ [SEND-CONFIRM] Neither gfpromptsent nor "
            f"imagerenderingprogress was detected within "
            f"{max_confirm_seconds}s for {entry_name}."
        )
        print(
            "🔁 [SEND-CONFIRM] Returning needs_restart so the existing send loop "
            "can re-search the prompt input, re-paste, and send again."
        )

        try:
            hud.print(
                "Prompt confirmation not detected — trying the prompt again.",
                "warning"
            )
        except Exception:
            pass

        update_operation_status(
            f"No gfpromptsent or imagerenderingprogress confirmation for "
            f"{entry_name} — re-searching input, re-pasting, and resending"
        )

        return "needs_restart"


    def wait_for_imagerenderingprogress_and_jump_to_search(hwnd, entry_name, max_wait_seconds=300, check_interval=0.4):
        print(f"🔍 [GF-FASTPATH] Looking for imagerenderingprogress.png (fast-path)...")
        start_time = time.time()
        cursor_placed = False
        search_location = None

        while time.time() - start_time < max_wait_seconds:
            check_for_termination()
            enforce_window_focus(hwnd)
            if check_for_tryagain_and_abort():
                return False
            check_and_click_done_editing()
            rendering_location = find_image("imagerenderingprogress.png", confidence=0.7)
            if rendering_location:
                print(f"✅ [GF-FASTPATH] Found imagerenderingprogress.png at ({rendering_location.x}, {rendering_location.y})")
                break
            time.sleep(check_interval)
        else:
            print(f"ℹ️ [GF-FASTPATH] imagerenderingprogress.png never appeared within {max_wait_seconds}s — falling back to normal flow")
            return False

        print(f"🔍 [GF-FASTPATH] Now looking for searchgfimage.png to place cursor 100px below it...")
        search_location = find_image("searchgfimage.png", confidence=0.7)
        if search_location:
            move_cursor_below_search(search_location, offset_px=100)
            cursor_placed = True
            print(f"✅ [GF-FASTPATH] Cursor placed 100px below searchgfimage.png (same column)")
        else:
            print(f"⚠️ [GF-FASTPATH] searchgfimage.png not found yet — will keep checking while rendering...")

        print(f"🔍 [GF-FASTPATH] Monitoring imagerenderingprogress.png until it disappears...")
        try:
            hud.print("Your image is almost ready...", "processing")
        except Exception:
            pass
        update_operation_status(f"Rendering in progress for {entry_name}...")
        render_seen_time = time.time()
        last_status_time = render_seen_time

        monitor_start = time.time()
        while time.time() - monitor_start < max_wait_seconds:
            check_for_termination()
            enforce_window_focus(hwnd)
            if check_for_tryagain_and_abort():
                return False
            check_and_click_done_editing()
            if not cursor_placed:
                search_location = find_image("searchgfimage.png", confidence=0.7)
                if search_location:
                    move_cursor_below_search(search_location, offset_px=100)
                    cursor_placed = True
                    print(f"✅ [GF-FASTPATH] Cursor placed 100px below searchgfimage.png (delayed placement)")
            rendering_location = find_image("imagerenderingprogress.png", confidence=0.7)
            if not rendering_location:
                print(f"✅ [GF-FASTPATH] imagerenderingprogress.png disappeared — rendering complete!")
                try:
                    hud.print("Your image is ready.", "success")
                except Exception:
                    pass
                update_operation_status(f"Image generation complete for {entry_name} (fast-path)")
                return True
            current_time = time.time()
            if current_time - last_status_time > 10:
                elapsed = int(current_time - render_seen_time)
                print(f"⏳ [GF-FASTPATH] Still rendering... ({elapsed}s since render started)")
                try:
                    hud.print("Google Flow image generation in progress... ", "processing")
                except Exception:
                    pass
                update_operation_status("Google Flow image generation in progress... ")
                last_status_time = current_time
            time.sleep(check_interval)

        print(f"⏰ [GF-FASTPATH] Timed out monitoring rendering for {entry_name} after {max_wait_seconds}s.")
        return False

    def wait_for_generation_complete_gf(hwnd, entry_name, max_wait_seconds=300, check_interval=0.5):
        print(f"⏳ [GF] Waiting for generation to complete...")
        print(f"🚀 [GF] Attempting fast-path via imagerenderingprogress.png...")
        fastpath_done = wait_for_imagerenderingprogress_and_jump_to_search(
            hwnd, entry_name, max_wait_seconds=max_wait_seconds, check_interval=check_interval
        )
        if fastpath_done:
            print(f"🎉 [GF] Fast-path completed — rendering finished, proceeding to download")
            return True, "complete"
        else:
            print(f"ℹ️ [GF] Fast-path did not trigger — falling back to normal generation wait flow")

        status_counter = 0
        try:
            hud.print("Google Flow image generation in progress... ", "processing")
        except Exception:
            pass
        update_operation_status("Google Flow image generation in progress... ")

        start_time = time.time()
        found_generating = False
        generating_first_seen = None

        print(f"🔍 [GF] Phase 1: Waiting for generatinggf_image.png...")
        while time.time() - start_time < max_wait_seconds:
            check_for_termination()
            enforce_window_focus(hwnd)
            if check_for_tryagain_and_abort():
                return False, "poor_network"
            check_and_click_done_editing()
            generating_location = find_image("generatinggf_image.png", confidence=0.7)
            if generating_location:
                print(f"✅ [GF] Found generatinggf_image.png - generation in progress!")
                found_generating = True
                generating_first_seen = time.time()
                status_counter += 1
                try:
                    hud.print("Google Flow image generation in progress... ", "processing")
                except Exception:
                    pass
                update_operation_status("Google Flow image generation in progress... ")
                break
            elapsed = int(time.time() - start_time)
            if elapsed % 10 == 0 and elapsed > 0:
                status_counter += 1
                print(f"⏳ [GF] Google Flow image generation in progress... ({elapsed}s elapsed, counter={status_counter})")
                try:
                    hud.print("Google Flow image generation in progress... ", "processing")
                except Exception:
                    pass
                update_operation_status("Google Flow image generation in progress... ")
            time.sleep(check_interval)

        if not found_generating:
            print(f"⚠️ [GF] generatinggf_image.png not found within timeout — checking fallbacks...")
            if check_for_tryagain_and_abort():
                return False, "poor_network"
            print(f"🔍 [GF] Checking for no_active_gf_generation.png directly...")
            check_and_click_done_editing()
            no_active_location = find_image("no_active_gf_generation.png", confidence=0.7)
            if no_active_location:
                print(f"✅ [GF] Found no_active_gf_generation.png directly — image is ready!")
                try:
                    hud.print("Your image is ready.", "success")
                except Exception:
                    pass
                update_operation_status(f"Image generation complete for {entry_name}")
                return True, "complete"
            recheck = handle_exit_gfsidebar_and_recheck()
            if recheck:
                print(f"✅ [GF] Found no_active_gf_generation.png after exit_gfsidebar fallback — image is ready!")
                try:
                    hud.print("Your image is ready.", "success")
                except Exception:
                    pass
                update_operation_status(f"Image generation complete for {entry_name}")
                return True, "complete"
            print(f"❌ [GF] Could not confirm generation completion after all fallbacks")
            return False, "timeout"

        print(f"🔍 [GF] Phase 2: Waiting for generatinggf_image.png to disappear...")
        status_counter += 1
        try:
            hud.print("Google Flow image generation in progress... ", "processing")
        except Exception:
            pass
        update_operation_status("Google Flow image generation in progress... ")

        phase2_start = time.time()
        phase2_timeout = max_wait_seconds - (generating_first_seen - start_time) if generating_first_seen else max_wait_seconds - 30
        if phase2_timeout < 30:
            phase2_timeout = 30

        while time.time() - phase2_start < phase2_timeout:
            check_for_termination()
            enforce_window_focus(hwnd)
            if check_for_tryagain_and_abort():
                return False, "poor_network"
            check_and_click_done_editing()
            generating_location = find_image("generatinggf_image.png", confidence=0.7)
            if not generating_location:
                print(f"✅ [GF] generatinggf_image.png disappeared - checking for completion...")
                time.sleep(0.5)
                check_and_click_done_editing()
                no_active_location = find_image("no_active_gf_generation.png", confidence=0.7)
                if no_active_location:
                    print(f"✅ [GF] Found no_active_gf_generation.png - image is ready!")
                    try:
                        hud.print("Your image is ready.", "success")
                    except Exception:
                        pass
                    update_operation_status(f"Image generation complete for {entry_name}")
                    return True, "complete"
                else:
                    print(f"⚠️ [GF] no_active_gf_generation.png not found yet - trying exit_gfsidebar fallback...")
                    recheck = handle_exit_gfsidebar_and_recheck()
                    if recheck:
                        print(f"✅ [GF] Found no_active_gf_generation.png after exit_gfsidebar fallback - image is ready!")
                        try:
                            hud.print("Your image is ready.", "success")
                        except Exception:
                            pass
                        update_operation_status(f"Image generation complete for {entry_name}")
                        return True, "complete"
                    else:
                        print(f"⚠️ [GF] Still not found - continuing to wait...")
                        for _ in range(10):
                            check_for_termination()
                            time.sleep(0.5)
                            if check_for_tryagain_and_abort():
                                return False, "poor_network"
                            check_and_click_done_editing()
                            no_active_location = find_image("no_active_gf_generation.png", confidence=0.7)
                            if no_active_location:
                                print(f"✅ [GF] Found no_active_gf_generation.png - image is ready!")
                                try:
                                    hud.print("Your image is ready.", "success")
                                except Exception:
                                    pass
                                update_operation_status(f"Image generation complete for {entry_name}")
                                return True, "complete"
                        continue
            else:
                elapsed = int(time.time() - phase2_start)
                if elapsed % 10 == 0 and elapsed > 0:
                    status_counter += 1
                    print(f"⏳ [GF] Google Flow image generation in progress... ({elapsed}s elapsed in phase 2, counter={status_counter})")
                    try:
                        hud.print("Google Flow image generation in progress... ", "processing")
                    except Exception:
                        pass
                    update_operation_status("Google Flow image generation in progress... ")
                time.sleep(check_interval)

        print(f"⏰ [GF] Generation timeout after {max_wait_seconds} seconds")
        return False, "timeout"

    # --- DOWNLOAD HELPERS (same as original) ---
    def check_and_rename_download(entry_name, images_folder, timeout_seconds=180, check_interval=0.3):
        print(f"📊 [DOWNLOAD] Monitoring for download for {entry_name}...")
        try:
            hud.print("Waiting for the download to finish...", "waiting")
        except Exception:
            pass
        update_operation_status(f"Waiting for download: {entry_name}")

        downloads_folder = os.path.expanduser("~/Downloads")
        if not os.path.exists(downloads_folder):
            print(f"❌ [DOWNLOAD] Downloads folder not found: {downloads_folder}")
            return False, None

        initial_files = {}
        try:
            with os.scandir(downloads_folder) as entries:
                for entry in entries:
                    if entry.is_file():
                        try:
                            stat = entry.stat()
                            initial_files[entry.name] = {'path': entry.path, 'time': stat.st_mtime, 'size': stat.st_size}
                        except Exception:
                            pass
            print(f"📁 [DOWNLOAD] Found {len(initial_files)} existing files before download")
        except Exception as e:
            print(f"⚠️ [DOWNLOAD] Error scanning existing files: {e}")
            initial_files = {}

        start_time = time.time()
        download_detected = False
        downloaded_file_path = None
        image_extensions = {'.png', '.jpg', '.jpeg', '.gif', '.webp', '.bmp', '.tiff'}

        while time.time() - start_time < timeout_seconds:
            check_for_termination()
            check_and_click_done_editing()
            elapsed = int(time.time() - start_time)
            try:
                if os.path.exists(downloads_folder):
                    with os.scandir(downloads_folder) as entries:
                        for entry in entries:
                            if entry.is_file():
                                file_name = entry.name
                                file_ext = os.path.splitext(file_name)[1].lower()
                                is_new_file = file_name not in initial_files
                                is_recent = False
                                try:
                                    stat = entry.stat()
                                    if stat.st_mtime > start_time - 10:
                                        is_recent = True
                                except Exception:
                                    pass
                                if (is_new_file or is_recent) and file_ext in image_extensions:
                                    print(f"🆕 [DOWNLOAD] Found new/updated image file: {file_name}")
                                    print(f"   📁 Path: {entry.path}")
                                    print(f"   📏 Size: {stat.st_size} bytes")
                                    stable_count = 0
                                    stable_size = 0
                                    max_stable_checks = 5
                                    while stable_count < 3 and stable_count < max_stable_checks:
                                        try:
                                            current_size = os.path.getsize(entry.path)
                                            if current_size == stable_size and current_size > 1024:
                                                stable_count += 1
                                                print(f"   ⏳ File stable ({stable_count}/3), size: {current_size} bytes")
                                            else:
                                                stable_count = 0
                                                stable_size = current_size
                                                print(f"   ⏳ File size changing: {current_size} bytes")
                                        except Exception as e:
                                            print(f"   ⚠️ Error checking size: {e}")
                                        time.sleep(0.3)
                                    if stable_count >= 3:
                                        print(f"✅ [DOWNLOAD] File stable and ready!")
                                        downloaded_file_path = entry.path
                                        download_detected = True
                                        break
                                    else:
                                        print(f"⚠️ [DOWNLOAD] File not stable, continuing to monitor...")
                                elif file_name.endswith('.crdownload') or file_name.endswith('.tmp'):
                                    print(f"⏳ [DOWNLOAD] Found temp download: {file_name}")
                            if download_detected:
                                break
                # A completed background download takes priority. Once a new
                # image file is detected, stop checking the on-screen upscaling
                # indicator and proceed to file-stability/rename handling.
                if download_detected and downloaded_file_path:
                    break

                # Visual fallback/progress signal: if this indicator remains
                # visible, Flow is still upscaling/processing the image. Keep
                # monitoring the background Downloads folder until a file is
                # actually detected or the same download timeout expires.
                try:
                    upscaling_location = find_image("upscalinggfimage.png", confidence=0.7)
                    if upscaling_location:
                        print(
                            f"⏳ [DOWNLOAD] upscalinggfimage.png is visible at "
                            f"({upscaling_location.x}, {upscaling_location.y}); "
                            "download/upscaling still appears in progress. "
                            "Continuing background file monitoring."
                        )
                except Exception as visual_error:
                    print(f"⚠️ [DOWNLOAD] Visual upscaling check failed: {visual_error}")

                if elapsed % 10 == 0 and elapsed > 0:
                    print(f"⏳ [DOWNLOAD] Still waiting for download... ({elapsed}s elapsed)")
                    try:
                        hud.print(f"Still downloading... ({elapsed}s)", "waiting")
                    except Exception:
                        pass
                    update_operation_status(f"Waiting for download {entry_name}... ({elapsed}s)")
                time.sleep(check_interval)
            except Exception as e:
                print(f"⚠️ [DOWNLOAD] Error in monitoring loop: {e}")
                time.sleep(check_interval)
                continue

        if not download_detected or not downloaded_file_path:
            print(f"⏰ [DOWNLOAD] Timeout or no download detected after {timeout_seconds} seconds")
            try:
                print(f"🔍 [DOWNLOAD] Performing final scan for new image files...")
                with os.scandir(downloads_folder) as entries:
                    for entry in entries:
                        if entry.is_file():
                            file_name = entry.name
                            file_ext = os.path.splitext(file_name)[1].lower()
                            if file_ext in image_extensions:
                                try:
                                    stat = entry.stat()
                                    if stat.st_mtime > start_time - 10:
                                        print(f"🆕 [DOWNLOAD] Found new file in final scan: {file_name}")
                                        downloaded_file_path = entry.path
                                        download_detected = True
                                        break
                                except Exception:
                                    pass
            except Exception as e:
                print(f"⚠️ [DOWNLOAD] Final scan error: {e}")

        if not download_detected or not downloaded_file_path:
            print(f"❌ [DOWNLOAD] No download detected")
            return False, None

        try:
            file_name = os.path.basename(downloaded_file_path)
            file_ext = os.path.splitext(file_name)[1].lower()
            new_filename = f"{entry_name}{file_ext}"
            target_path = os.path.join(images_folder, new_filename)
            if not os.path.exists(images_folder):
                os.makedirs(images_folder, exist_ok=True)
            if not os.path.exists(downloaded_file_path):
                print(f"❌ [DOWNLOAD] File no longer exists: {downloaded_file_path}")
                return False, None
            try:
                time.sleep(0.5)
                os.rename(downloaded_file_path, target_path)
                print(f"✅ [DOWNLOAD] File renamed to: {new_filename}")
                print(f"📁 [DOWNLOAD] Moved to: {target_path}")
                try:
                    hud.print("Saved successfully.", "success")
                except Exception:
                    pass
                update_operation_status(f"Downloaded and saved: {new_filename}")
                return True, target_path
            except Exception as e:
                print(f"⚠️ [DOWNLOAD] Error moving file: {e}")
                import shutil
                try:
                    shutil.copy2(downloaded_file_path, target_path)
                    try:
                        os.remove(downloaded_file_path)
                    except Exception:
                        pass
                    print(f"✅ [DOWNLOAD] File copied and renamed: {new_filename}")
                    return True, target_path
                except Exception as e2:
                    print(f"❌ [DOWNLOAD] Failed to save file: {e2}")
                    return False, None
        except Exception as e:
            print(f"❌ [DOWNLOAD] Error processing downloaded file: {e}")
            return False, None

    def download_google_flow_image(hwnd, entry_name, images_folder):
        print(f"📥 [GF-DOWNLOAD] Starting Google Flow download procedure for {entry_name}...")
        try:
            hud.print("Preparing the download...", "downloading")
        except Exception:
            pass
        update_operation_status(f"Starting download for {entry_name}...")

        print(f"🔍 [GF-DOWNLOAD] Step 1: Looking for searchgfimage.png...")
        check_and_click_done_editing()
        search_location = find_image("searchgfimage.png", confidence=0.7)
        if search_location:
            move_cursor_below_search(search_location, offset_px=100)
        else:
            print(f"⚠️ [GF-DOWNLOAD] searchgfimage.png not found — continuing anyway...")

        print(f"⬇️ [GF-DOWNLOAD] Step 2: Scrolling DOWN at current cursor position...")
        check_and_click_done_editing()
        scroll_down_multiple(2)

        print(f"🔍 [GF-DOWNLOAD] Step 3: Looking for moregf.png...")
        moregf_found = False
        for attempt in range(5):
            check_and_click_done_editing()
            moregf_location = find_image("moregf.png", confidence=0.7)
            if moregf_location:
                clicked = click_right_edge_of_image("moregf.png", confidence=0.7)
                if clicked:
                    moregf_found = True
                break
            else:
                print(f"⏳ [GF-DOWNLOAD] moregf.png not found (attempt {attempt+1}/5) — scrolling DOWN...")
                check_and_click_done_editing()
                scroll_down_multiple(2)
                time.sleep(0.5)

        if not moregf_found:
            print(f"⚠️ [GF-DOWNLOAD] moregf.png not found after multiple attempts — continuing anyway...")

        print(f"🔍 [GF-DOWNLOAD] Step 4: Looking for downloadgfimage.png...")
        check_and_click_done_editing()
        download_location = wait_for_image("downloadgfimage.png", timeout_seconds=20, confidence=0.7)
        if download_location:
            pyautogui.moveTo(download_location.x, download_location.y, duration=0.1)
            pyautogui.click()
            time.sleep(0.5)
            print(f"✅ [GF-DOWNLOAD] Clicked downloadgfimage.png")
        else:
            print(f"❌ [GF-DOWNLOAD] downloadgfimage.png not found — download cannot proceed")
            check_and_click_done_editing()
            return False

        print(f"🔍 [GF-DOWNLOAD] Step 5: Looking for gfdownloadquality.png...")
        check_and_click_done_editing()
        quality_location = wait_for_image("gfdownloadquality.png", timeout_seconds=15, confidence=0.7)
        if quality_location:
            pyautogui.moveTo(quality_location.x, quality_location.y, duration=0.1)
            pyautogui.click()
            time.sleep(0.5)
            print(f"✅ [GF-DOWNLOAD] Clicked gfdownloadquality.png")
        else:
            print(f"⚠️ [GF-DOWNLOAD] gfdownloadquality.png not found — download may still proceed...")

        print(f"📊 [GF-DOWNLOAD] Step 6: Monitoring background for new download...")
        try:
            hud.print("Downloading your image...", "downloading")
        except Exception:
            pass
        update_operation_status(f"Downloading {entry_name}...")

        download_success = False
        file_path = None
        download_attempts = 0
        max_download_attempts = 3

        while download_attempts < max_download_attempts and not download_success:
            download_attempts += 1
            print(f"🔄 [GF-DOWNLOAD] Download attempt {download_attempts}/{max_download_attempts}")
            check_and_click_done_editing()
            timeout_seconds = 30 if download_attempts == 1 else 20
            download_success, file_path = check_and_rename_download(
                entry_name, images_folder, timeout_seconds=timeout_seconds, check_interval=0.3
            )
            if download_success:
                print(f"✅ [GF-DOWNLOAD] Download successful on attempt {download_attempts}")
                break
            if download_attempts < max_download_attempts:
                print(f"⚠️ [GF-DOWNLOAD] Download not found after {timeout_seconds}s — retrying...")
                try:
                    hud.print(f"Retrying the download... ({download_attempts}/{max_download_attempts})", "warning")
                except Exception:
                    pass
                update_operation_status(f"Retrying download for {entry_name}... (attempt {download_attempts + 1})")

                # Restore the same hover position used while the submitted image
                # is rendering. Google Flow may reveal image actions only when
                # the pointer is hovering over the generated image.
                check_for_termination()
                search_location = find_image("searchgfimage.png", confidence=0.7)
                if search_location:
                    move_cursor_below_search(search_location, offset_px=100)
                    print(
                        "🖱️ [GF-DOWNLOAD-RETRY] Restored cursor to the "
                        "rendering/hover position before retrying download."
                    )
                    time.sleep(0.5)
                else:
                    print(
                        "⚠️ [GF-DOWNLOAD-RETRY] searchgfimage.png not found; "
                        "continuing with the existing download retry flow."
                    )

                check_and_click_done_editing()
                click_right_edge_of_image("moregf.png", confidence=0.7)
                check_and_click_done_editing()
                download_location = wait_for_image("downloadgfimage.png", timeout_seconds=10, confidence=0.7)
                if download_location:
                    pyautogui.moveTo(download_location.x, download_location.y, duration=0.1)
                    pyautogui.click()
                    time.sleep(0.5)
                check_and_click_done_editing()
                quality_location = wait_for_image("gfdownloadquality.png", timeout_seconds=10, confidence=0.7)
                if quality_location:
                    pyautogui.moveTo(quality_location.x, quality_location.y, duration=0.1)
                    pyautogui.click()
                    time.sleep(0.5)
                time.sleep(2)

        if download_success:
            print(f"🎉 [GF-DOWNLOAD] {entry_name} downloaded and saved successfully!")
            try:
                hud.print("Image downloaded successfully.", "success")
            except Exception:
                pass
            update_operation_status(f"{entry_name} completed successfully", is_success=True)
            return True
        else:
            print(f"❌ [GF-DOWNLOAD] Failed to download {entry_name} after {max_download_attempts} attempts")
            try:
                hud.print("Could not download the image.", "error")
            except Exception:
                pass
            update_operation_status(f"Failed to download {entry_name} after multiple attempts", is_error=True)
            return False

    def handle_webdownloadconfirmationmodal():
        """
        Handles webdownloadconfirmationmodal.png using a mandatory 2-step
        verification.

        The confirmation button may ONLY be clicked when BOTH of these
        images are currently visible on screen:

            1. webdownloadconfirmationmodal.png
            2. webdownloadmodalappearance.png

        If webdownloadconfirmationmodal.png is visible but
        webdownloadmodalappearance.png is NOT visible, the confirmation
        button is NOT clicked and the function skips it entirely.
        """

        print(f"🔍 [MODAL] Step 1: Checking for webdownloadconfirmationmodal.png...")
        modal_location = find_image(
            "webdownloadconfirmationmodal.png",
            confidence=0.7
        )

        if not modal_location:
            print(
                f"ℹ️ [MODAL] webdownloadconfirmationmodal.png not found "
                f"— proceeding normally"
            )
            return False

        print(
            f"✅ [MODAL] webdownloadconfirmationmodal.png found at "
            f"({modal_location.x}, {modal_location.y})"
        )

        # --------------------------------------------------------------
        # REQUIRED SECOND VERIFICATION
        # --------------------------------------------------------------
        print(
            f"🔍 [MODAL] Step 2: Checking for webdownloadmodalappearance.png..."
        )

        modal_appearance_location = find_image(
            "webdownloadmodalappearance.png",
            confidence=0.7
        )

        if not modal_appearance_location:
            print(
                f"⚠️ [MODAL] webdownloadconfirmationmodal.png was found, "
                f"BUT webdownloadmodalappearance.png was NOT found."
            )
            print(
                f"⏭️ [MODAL] 2-step verification FAILED — "
                f"SKIPPING webdownloadconfirmationmodal click entirely."
            )
            return False

        print(
            f"✅ [MODAL] webdownloadmodalappearance.png found at "
            f"({modal_appearance_location.x}, "
            f"{modal_appearance_location.y})"
        )

        # BOTH required images are visible, so clicking is now permitted.
        print(
            f"🔐 [MODAL] 2-step verification PASSED — "
            f"both required images are visible."
        )
        print(
            f"🖱️ [MODAL] Clicking webdownloadconfirmationmodal.png..."
        )

        try:
            hud.print("Confirming the download...", "warning")
        except Exception:
            pass

        pyautogui.moveTo(
            modal_location.x,
            modal_location.y,
            duration=0.1
        )
        pyautogui.click()
        time.sleep(0.5)
        move_mouse_to_center_of_screen()
        time.sleep(0.3)

        # --------------------------------------------------------------
        # RE-CHECK AFTER THE FIRST CLICK
        # --------------------------------------------------------------
        print(
            f"🔍 [MODAL] Re-checking both verification images "
            f"after first click..."
        )

        recheck_appearance_location = find_image(
            "webdownloadmodalappearance.png",
            confidence=0.7
        )

        recheck_modal_location = find_image(
            "webdownloadconfirmationmodal.png",
            confidence=0.7
        )

        if recheck_modal_location and recheck_appearance_location:
            print(
                f"⚠️ [MODAL] Both verification images are still visible "
                f"after the first click — clicking confirmation again..."
            )

            pyautogui.moveTo(
                recheck_modal_location.x,
                recheck_modal_location.y,
                duration=0.1
            )
            pyautogui.click()
            time.sleep(0.5)
            move_mouse_to_center_of_screen()
            time.sleep(0.3)

            print(
                f"✅ [MODAL] Second click completed with both "
                f"verification conditions satisfied."
            )

        elif recheck_modal_location and not recheck_appearance_location:
            print(
                f"ℹ️ [MODAL] webdownloadconfirmationmodal.png is still "
                f"visible, but webdownloadmodalappearance.png is no longer "
                f"visible — NOT clicking again."
            )
            print(
                f"⏭️ [MODAL] Skipping the confirmation button because "
                f"the required 2-step verification is no longer satisfied."
            )

        elif not recheck_modal_location:
            print(
                f"✅ [MODAL] webdownloadconfirmationmodal.png is no longer "
                f"visible — confirmation completed."
            )

        else:
            print(
                f"ℹ️ [MODAL] Verification state changed after the click — "
                f"no additional click required."
            )

        print(
            f"✅ [MODAL] Modal handling completed — proceeding with "
            f"normal protocol"
        )
        return True

    def validate_entry_order(images_folder):
        if not os.path.exists(images_folder):
            os.makedirs(images_folder, exist_ok=True)
            return True, 0
        image_files = []
        for file in os.listdir(images_folder):
            if file.endswith(('.png', '.jpg', '.jpeg', '.gif', '.webp')):
                image_files.append(file)
        if not image_files:
            print(f"📁 [VALIDATE] No image files found in {images_folder}")
            return True, 0
        entry_numbers = []
        for filename in image_files:
            match = re.search(r'entry[_\-]?(\d+)\.(png|jpg|jpeg|gif|webp)$', filename, re.IGNORECASE)
            if match:
                entry_numbers.append(int(match.group(1)))
        if not entry_numbers:
            print(f"⚠️ [VALIDATE] No valid entry files found, starting from 0")
            return True, 0
        entry_numbers.sort()
        print(f"📊 [VALIDATE] Found entry numbers: {entry_numbers}")
        expected_next = 1
        for num in entry_numbers:
            if num != expected_next:
                print(f"❌ [VALIDATE] Gap detected: Expected entry {expected_next}, found entry {num}")
                print(f"🗑️ [VALIDATE] Deleting all image files and starting over...")
                try:
                    hud.print("Starting fresh to keep the sequence correct...", "warning")
                except Exception:
                    pass
                update_operation_status("Gap detected - resetting all entries")
                for file in image_files:
                    file_path = os.path.join(images_folder, file)
                    try:
                        os.remove(file_path)
                        print(f"🗑️ [VALIDATE] Deleted: {file}")
                    except Exception as e:
                        print(f"⚠️ [VALIDATE] Could not delete {file}: {e}")
                return True, 0
            expected_next += 1
        next_entry = len(entry_numbers)
        print(f"✅ [VALIDATE] Entries are in order, next entry: {next_entry + 1}")
        return True, next_entry

    def process_single_entry_gf(hwnd, entry_index, entry_data, images_folder):
        entry_name = f"entry_{entry_index + 1}"
        entry_json = format_entry_for_paste(entry_data)
        if not entry_json:
            print(f"⚠️ [SCENE] No data found for {entry_name}")
            return False

        print(f"\n{'='*50}")
        print(f"📝 [SCENE] Working on scene {entry_index + 1} of {total_entries}")
        print(f"{'='*50}")
        update_operation_status(f"Working on scene {entry_index + 1} of {total_entries}...")
        try:
            hud.print(f"Working on scene {entry_index + 1} of {total_entries}...", "processing")
        except Exception:
            pass

        send_attempt = 0
        send_confirmed = False
        while not send_confirmed:
            send_attempt += 1
            print(f"\n🔁 [SEND-LOOP] Send attempt #{send_attempt} for {entry_name}")
            update_operation_status(f"Preparing to send scene {entry_index + 1} (attempt {send_attempt})...")
            check_and_click_done_editing()
            send_coords = paste_prompt_and_click_send(hwnd, entry_json, entry_name)
            if send_coords is None:
                print(f"❌ [SEND-LOOP] Could not paste & click send for {entry_name} — aborting")
                return False
            send_x, send_y = send_coords
            print(f"🔎 [SEND-LOOP] Entering send-confirmation phase (attempt #{send_attempt})...")
            confirm_result = confirm_sendgf_and_wait_for_generating(
                hwnd, entry_name, entry_json,
                send_x, send_y,
                max_confirm_seconds=30, check_interval=0.4
            )
            if confirm_result == "confirmed":
                print(f"✅ [SEND-LOOP] Confirmed: prompt truly sent")
                send_confirmed = True
                break
            elif confirm_result == "needs_restart":
                print(
                    f"🔁 [SEND-LOOP] gfpromptsent confirmation was not detected — "
                    f"re-searching input, re-pasting, and sending again"
                )
                try:
                    hud.print(
                        "Prompt confirmation not found — re-searching the input and sending again.",
                        "warning"
                    )
                except Exception:
                    pass
                update_operation_status(
                    f"gfpromptsent not found for {entry_name} — "
                    f"re-searching input, re-pasting, and resending "
                    f"(attempt {send_attempt + 1})..."
                )
                time.sleep(1.5)
                continue
            elif confirm_result == "timeout":
                print(f"🔁 [SEND-LOOP] Confirmation timed out — treating as needs_restart")
                try:
                    hud.print("The prompt didn't go through — trying again.", "warning")
                except Exception:
                    pass
                update_operation_status(f"Restarting send for {entry_name} (attempt {send_attempt + 1})...")
                time.sleep(1.5)
                continue
            elif confirm_result == "poor_network":
                print(f"❌ [SEND-LOOP] Poor network detected during send confirmation — aborting")
                return False
            elif confirm_result == "aborted":
                print(f"🛑 [SEND-LOOP] Send confirmation aborted by user")
                return False
            else:
                print(f"⚠️ [SEND-LOOP] Unexpected confirm result '{confirm_result}' — restarting")
                time.sleep(1.5)
                continue

        print(f"⏳ [SCENE] Step 4: Waiting for the image to be created...")
        generation_complete, status = wait_for_generation_complete_gf(hwnd, entry_name, max_wait_seconds=300)

        if not generation_complete:
            print(f"❌ [SCENE] Image creation did not complete: {status}")
            try:
                hud.print("The image could not be created in time.", "error")
            except Exception:
                pass
            update_operation_status(f"Generation failed for scene {entry_index + 1}: {status}", is_error=True)
            if status == "poor_network":
                print(f"❌ [SCENE] Poor network detected — operation aborted")
                return False
            abort_operation(f"Generation failed for scene {entry_index + 1}: {status}")
            return False

        print(f"📥 [SCENE] Step 5: Downloading the generated image...")
        download_success = download_google_flow_image(hwnd, entry_name, images_folder)
        if not download_success:
            print(f"❌ [SCENE] Download failed for {entry_name} — aborting entire operation (never skip)")
            try:
                hud.print("The download did not finish — stopping here.", "error")
            except Exception:
                pass
            update_operation_status(f"Download failed for {entry_name} — aborting", is_abort=True)
            abort_operation(f"Download failed for {entry_name}")
            return False

        print(f"🎉 [SCENE] Scene {entry_index + 1} image is ready and downloaded!")
        try:
            hud.print(f"Scene {entry_index + 1} is ready.", "success")
        except Exception:
            pass
        update_operation_status(f"Scene {entry_index + 1} complete", is_success=True)
        return True

    def main_google_flow_workflow():
        try:
            if not os.path.exists(GUI_IMAGES):
                print(f"❌ Error: GUI_IMAGES path not found: {GUI_IMAGES}")
                try:
                    hud.print("A required folder is missing.", "error")
                except Exception:
                    pass
                update_operation_status(f"GUI_IMAGES path not found: {GUI_IMAGES}", is_error=True)
                return

            images_folder = os.path.join(IMAGES_PATH, project_title)
            print(f"🎬 [MAIN] Starting Google Flow image generation workflow...")
            print(f"🖥️ [MAIN] GUI Images path: {GUI_IMAGES}")
            print(f"📁 [MAIN] Project: '{project_title}'")
            print(f"📁 [MAIN] Images folder: {images_folder}")
            print(f"📊 [MAIN] Total entries: {total_entries}")

            try:
                hud.print(f"Preparing to generate {total_entries} images...", "initializing")
            except Exception:
                pass

            valid, start_index = validate_entry_order(images_folder)
            if not valid:
                print(f"❌ [MAIN] Entry validation failed")
                return
            if start_index >= total_entries:
                print(f"✅ [MAIN] All {total_entries} entries already processed!")
                try:
                    hud.print("All images are already complete.", "success")
                except Exception:
                    pass
                transferred = _transfer_completed_project_to_ready_script(images_folder, total_entries)
                if transferred:
                    _set_currently_running(
                        "generate_google_flow_images_successful",
                        status="completed",
                        operation_status=f"✅ All {total_entries} entries verified and transferred to ready_script.json"
                    )
                    print("🏁 [GF] Completed project appended to ready_script.json")
                return

            print(f"📊 [MAIN] Starting from entry {start_index + 1}")

            hwnd = ensure_window_ready_and_focused()
            print(f"🪟 [MAIN] Browser ready (HWND: {hwnd})")
            update_operation_status("Browser initialized for Google Flow operation")

            # Detect the current Google Flow page BEFORE navigating. Check the
            # furthest-progressed page first so a restart resumes where it is:
            # prompt input -> media/add-media workspace -> New project home.
            def detect_current_flow_page():
                check_for_termination()
                enforce_window_focus(hwnd)

                prompt_location = find_image("whatdoyouwantocreate.png", confidence=0.8)
                if prompt_location:
                    print(
                        "✅ [RESUME] Prompt input is already visible. "
                        "Skipping URL navigation and workspace setup."
                    )
                    return "prompt"

                media_location = find_image("gfmedia.png", confidence=0.8)
                if media_location:
                    print(
                        "✅ [RESUME] Google Flow media/add-media workspace is already visible. "
                        "Skipping URL navigation and New project."
                    )
                    return "media"

                new_project_location = find_image("new_project.png", confidence=0.8)
                if new_project_location:
                    print(
                        "✅ [RESUME] New project home is already visible. "
                        "Skipping URL navigation and proceeding to click New project."
                    )
                    return "new_project"

                return None

            current_page = detect_current_flow_page()

            # Navigate to the URL only when none of the known workflow pages
            # is currently visible (for example, a newly launched blank browser).
            if current_page is None:
                print(
                    "🌐 [RESUME] No known Google Flow page detected. "
                    "Opening GOOGLE_FLOW_URL because the app may be newly launched."
                )
                try:
                    hud.print("Opening Google Flow...", "navigating")
                except Exception:
                    pass
                update_operation_status("No recognized Google Flow page; loading Google Flow URL...")
                fast_paste_url(hwnd, GOOGLE_FLOW_URL)
                time.sleep(3)
                hwnd = ensure_window_ready_and_focused()
                current_page = detect_current_flow_page()

            # If the URL opened but the page is still loading, wait for the
            # New project screen. The helper clicks it when it becomes visible.
            if current_page is None:
                print(
                    "🔍 [RESUME] No known workflow page visible after navigation. "
                    "Waiting for New project."
                )
                try:
                    hud.print("Looking for the new project option...", "waiting")
                except Exception:
                    pass
                success = wait_for_new_project_and_click(hwnd)
                if not success:
                    print("❌ [MAIN] Failed to find/click new_project.png")
                    try:
                        hud.print("Could not start a new project.", "error")
                    except Exception:
                        pass
                    update_operation_status("Failed to find new project button", is_error=True)
                    return
                current_page = "new_project_clicked"

            elif current_page == "new_project":
                # We are already on the home screen, so click it without reloading.
                print("🖱️ [RESUME] Clicking the already-visible New project button.")
                success = wait_for_new_project_and_click(hwnd)
                if not success:
                    print("❌ [MAIN] Failed to click the visible New project button")
                    update_operation_status("Failed to click new project button", is_error=True)
                    return
                current_page = "new_project_clicked"

            # Only wait for the media workspace if we just clicked New project.
            # If the media workspace or prompt input was already visible at
            # startup/restart, continue directly to processing scene entries.
            if current_page == "new_project_clicked":
                print("🔍 [MAIN] Waiting for gfmedia.png after clicking New project...")
                try:
                    hud.print("Waiting for the workspace to load...", "waiting")
                except Exception:
                    pass
                success = wait_for_gfmedia_and_confirm(hwnd)
                if not success:
                    print("❌ [MAIN] Failed to confirm page load (gfmedia.png)")
                    try:
                        hud.print("The workspace did not finish loading.", "error")
                    except Exception:
                        pass
                    update_operation_status("Failed to confirm page load", is_error=True)
                    return
            elif current_page == "media":
                print("⏭️ [RESUME] Media workspace already open; continuing to scene processing.")
            elif current_page == "prompt":
                print("⏭️ [RESUME] Prompt input already visible; continuing directly to scene processing.")

            print("✅ [MAIN] Google Flow is at a usable workflow stage; starting scene processing.")
            try:
                hud.print("Everything is ready. Starting now...", "success")
            except Exception:
                pass

            for i in range(start_index, total_entries):
                check_for_termination()
                entry = all_entries[i]
                if i > start_index:
                    download_confirmation_handled = handle_webdownloadconfirmationmodal()

                    # Only after the download-confirmation modal has been
                    # positively handled, check for generatinggf_image.png.
                    # If it is visible, click it before starting the next prompt.
                    if download_confirmation_handled:
                        check_for_termination()
                        enforce_window_focus(hwnd)
                        print(
                            "🔍 [NEXT-PROMPT] Download confirmation was handled. "
                            "Checking for generatinggf_image.png before the next prompt..."
                        )
                        generating_image_location = find_image(
                            "generatinggf_image.png",
                            confidence=0.7
                        )

                        if generating_image_location:
                            print(
                                "✅ [NEXT-PROMPT] Found generatinggf_image.png at "
                                f"({generating_image_location.x}, "
                                f"{generating_image_location.y}). "
                                "Clicking the RIGHT EDGE of the matched image."
                            )

                            # IMPORTANT: Do not click the match centre. Use the
                            # existing image-bounds helper to click at 95% of the
                            # matched template's width and its vertical midpoint.
                            right_edge_clicked = click_right_edge_of_image(
                                "generatinggf_image.png",
                                confidence=0.7
                            )

                            if right_edge_clicked:
                                time.sleep(0.5)
                                move_mouse_to_center_of_screen()
                                update_operation_status(
                                    "Clicked right edge of generatinggf_image.png "
                                    "after download confirmation"
                                )
                                print(
                                    "✅ [NEXT-PROMPT] Right edge of "
                                    "generatinggf_image.png clicked. "
                                    "Proceeding to the next prompt operation."
                                )
                            else:
                                print(
                                    "⚠️ [NEXT-PROMPT] generatinggf_image.png was "
                                    "detected, but its image bounds could not be "
                                    "located for a right-edge click. No centre click "
                                    "will be attempted; proceeding to the next prompt."
                                )
                        else:
                            print(
                                "ℹ️ [NEXT-PROMPT] generatinggf_image.png was not found. "
                                "No click needed — proceeding to the next prompt operation."
                            )

                success = process_single_entry_gf(hwnd, i, entry, images_folder)
                if success:
                    time.sleep(2)
                else:
                    print(f"❌ [MAIN] Scene {i+1} failed — aborting entire operation (never skip)")
                    try:
                        hud.print("Stopping — the current scene did not finish.", "error")
                    except Exception:
                        pass
                    update_operation_status(f"Entry {i+1} failed — aborting operation (never skip)", is_abort=True)
                    abort_operation(f"Entry {i+1} failed — aborting operation")
                    return

            print(f"\n{'='*50}")
            print(f"🎉 [MAIN] All entries processed and downloaded!")
            print(f"{'='*50}")
            try:
                hud.print("All images are ready.", "success")
            except Exception:
                pass

            transferred = _transfer_completed_project_to_ready_script(images_folder, total_entries)
            if transferred:
                _set_currently_running(
                    "generate_google_flow_images_successful",
                    status="completed",
                    operation_status=f"✅ All {total_entries} entries processed and transferred to ready_script.json"
                )
                print("🏁 [GF] Completed project appended to ready_script.json")
            else:
                print("⚠️ [GF] Images may be generated, but project transfer did not complete.")

        except KeyboardInterrupt as ki:
            update_operation_status("Google Flow operation manually terminated by user", is_abort=True)
            try:
                hud.show_summary("Stopped at your request.")
            except Exception:
                pass
            print(f"\n✅ Program successfully halted: {ki}")
        except SystemExit as se:
            print(f"🛑 System exit: {se}")
        except Exception as e:
            print(f"❌ [MAIN] Error: {e}")
            traceback.print_exc()
            error_msg = f"Unexpected error in Google Flow operation: {str(e)}"
            update_operation_status(error_msg, is_error=True)
            try:
                hud.print("Something went wrong.", "error")
            except Exception:
                pass
        finally:
            try:
                keyboard.remove_hotkey('alt+/')
                print("🧹 Cleaned up hotkey")
            except Exception:
                pass

    # ------------------------------------------------------------------
    # Automatic full-workflow restart supervisor
    # ------------------------------------------------------------------
    # Any unexpected failure, timeout, or failed scene causes the workflow
    # to start again without a retry limit. Alt+/ remains the explicit manual
    # stop control. Successful completion exits normally.
    restart_number = 0
    while True:
        if terminate_automation:
            print("🛑 [RESTART] Manual stop requested; not restarting.")
            break

        restart_number += 1
        operation_status_flag = True
        operation_status_message = ""
        operation_aborted = False

        print(
            f"\\n🔁 [RESTART] Starting Google Flow workflow from the beginning "
            f"(workflow attempt {restart_number}; unlimited automatic retries)."
        )
        _set_currently_running(
            "generate_google_flow_images()",
            status="pending",
            operation_status=f"Restarting Google Flow workflow (attempt {restart_number})"
        )

        try:
            keyboard.add_hotkey('alt+/', on_terminate_shortcut)
        except Exception as hotkey_error:
            print(f"⚠️ [RESTART] Could not register Alt+/ manual stop hotkey: {hotkey_error}")

        main_google_flow_workflow()

        # main_google_flow_workflow catches most failures internally, so inspect
        # the persisted state to decide whether it completed or should restart.
        final_panel = _load_panel()
        final_running = str(final_panel.get("currently_running", "") or "").strip()
        final_status = str(final_panel.get("status", "") or "").strip().lower()

        try:
            keyboard.remove_hotkey('alt+/')
        except Exception:
            pass

        if final_running == "generate_google_flow_images_successful":
            print("✅ [RESTART] Workflow completed successfully; supervisor is exiting.")
            break

        if terminate_automation:
            print("🛑 [RESTART] Workflow stopped manually; supervisor is exiting.")
            break

        print(
            f"⚠️ [RESTART] Workflow did not complete "
            f"(status={final_status or 'unknown'}, currently_running={final_running or 'empty'}). "
            "Waiting 3 seconds, then restarting from the beginning."
        )
        try:
            hud.print("The workflow encountered a problem. Restarting automatically...", "warning")
        except Exception:
            pass
        time.sleep(3)

def produce_video_from_images_entries():
    """
    Assemble entry_N images into a narrated video with animated captions.
    Caption animation:
      - Typewriter reveal: characters appear progressively.
      - Word emphasis: the word currently being revealed is highlighted.
      - Gentle pop-in: the caption line scales in slightly at the start.
      - Captions are rendered frame-by-frame over the image, not burned into
        the source image files.
    Keeps the original project lookup, script_json timing, audio matching,
    image normalization, and memory-conscious sequential clip assembly.
    Requires: PANEL_PATH, IMAGES_PATH, plus Pillow and MoviePy.
    """
    import os
    import re
    import json
    import shutil
    import traceback
    import time
    from PIL import Image, ImageDraw, ImageFont
    import numpy as np
    # Import from MoviePy's public package namespace. MoviePy 2.x removed
    # the old \\\\`moviepy.editor\\\\` module, so do not fall back to that path.
    try:
        from moviepy import VideoClip, AudioFileClip, concatenate_videoclips
    except ImportError as exc:
        print(
            "❌ MoviePy imports failed. Install/upgrade MoviePy with: "
            "python -m pip install --upgrade moviepy"
        )
        print(f"   Import details: {exc}")
        return None
    # READY_SCRIPT is the queue. Deduplicate by normalized project title,
    # then process the first project whose final video does not already exist.
    # Existing completed videos are skipped; no project is overwritten.
    if not os.path.exists(READY_SCRIPT):
        print(f"❌ READY_SCRIPT missing: {READY_SCRIPT}")
        return None

    def normalize_project_title(name):
        normalized = re.sub(r"[^a-zA-Z0-9\s_]", "", str(name or ""))
        normalized = re.sub(r"\s+", "_", normalized)
        normalized = re.sub(r"_+", "_", normalized).strip("_")
        return normalized or "unnamed_project"

    try:
        with open(READY_SCRIPT, "r", encoding="utf-8") as file:
            ready_data = json.load(file)
    except Exception as exc:
        print(f"❌ Error reading READY_SCRIPT: {exc}")
        return None

    if isinstance(ready_data, list):
        queued_projects = ready_data
    elif isinstance(ready_data, dict) and isinstance(ready_data.get("projects"), list):
        queued_projects = ready_data["projects"]
    elif isinstance(ready_data, dict) and isinstance(ready_data.get("project_name"), dict):
        queued_projects = [ready_data]  # legacy single-project format
    else:
        print("❌ READY_SCRIPT must contain a project list or a {'projects': [...]} object")
        return None

    unique_projects = []
    seen_titles = set()
    for candidate in queued_projects:
        if not isinstance(candidate, dict):
            continue
        candidate_info = candidate.get("project_name", {})
        candidate_title = (
            candidate_info.get("project_title")
            if isinstance(candidate_info, dict)
            else None
        ) or candidate.get("project_title")
        if not candidate_title or not str(candidate_title).strip():
            print("⚠️ [READY-SCRIPT] Skipping project without a title")
            continue
        title_key = normalize_project_title(candidate_title).casefold()
        if title_key in seen_titles:
            print(f"⏭️ [READY-SCRIPT] Duplicate project title skipped: {candidate_title}")
            continue
        seen_titles.add(title_key)
        unique_projects.append((candidate, str(candidate_title).strip()))

    panel_data = None
    project_title = None
    for candidate, candidate_title in unique_projects:
        normalized_candidate_title = normalize_project_title(candidate_title)
        candidate_folder = os.path.join(IMAGES_PATH, normalized_candidate_title)
        candidate_edited_folder = os.path.join(candidate_folder, "edited")
        expected_video = os.path.join(candidate_edited_folder, f"{normalized_candidate_title}.mp4")
        if os.path.isfile(expected_video) and os.path.getsize(expected_video) > 0:
            print(f"⏭️ [READY-SCRIPT] Video already exists; skipping '{candidate_title}': {expected_video}")
            continue
        candidate_script = candidate.get("project_name", {})
        candidate_script_json = candidate_script.get("script_json", {}) if isinstance(candidate_script, dict) else {}
        candidate_script_data = candidate_script_json.get("script") if isinstance(candidate_script_json, dict) else None
        if not isinstance(candidate_script_data, (dict, list)):
            print(f"⚠️ [READY-SCRIPT] Skipping '{candidate_title}': script_json.script is missing or invalid")
            continue
        candidate_images_folder = os.path.join(candidate_folder, "images")
        possible_folders = [candidate_images_folder, candidate_folder]
        if not any(
            os.path.isdir(folder) and any(
                re.match(r"entry[_-]?\d+", name, re.IGNORECASE)
                and os.path.splitext(name)[1].lower() in (".png", ".jpg", ".jpeg", ".gif", ".bmp", ".webp")
                for name in os.listdir(folder)
            )
            for folder in possible_folders
        ):
            print(f"⏭️ [READY-SCRIPT] No entry images found yet; skipping '{candidate_title}'")
            continue
        panel_data = candidate
        project_title = candidate_title
        print(f"🎯 [READY-SCRIPT] Selected next unfinished project: '{project_title}'")
        break

    if panel_data is None or not project_title:
        print("✅ [READY-SCRIPT] No unfinished projects remain (or no eligible project has images and script data).")
        return None
    normalized_project_title = normalize_project_title(project_title)
    project_folder = os.path.join(IMAGES_PATH, normalized_project_title)
    possible_image_folders = [
        os.path.join(project_folder, "images"),
        project_folder,
    ]
    source_folder = None
    for folder_path in possible_image_folders:
        if not os.path.isdir(folder_path):
            continue
        try:
            found = [
                name for name in os.listdir(folder_path)
                if name.lower().endswith((".png", ".jpg", ".jpeg", ".gif", ".bmp", ".webp"))
                and re.match(r"entry[_-]?\d+", name, re.IGNORECASE)
            ]
            if found:
                source_folder = folder_path
                print(f"✅ Found {len(found)} images in: {folder_path}")
                break
        except OSError as exc:
            print(f"⚠️ Could not inspect {folder_path}: {exc}")
    if not source_folder:
        print("❌ No entry images found")
        return None
    edited_folder = os.path.join(project_folder, "edited")
    temp_folder = os.path.join(project_folder, "temp_captions")
    os.makedirs(edited_folder, exist_ok=True)
    os.makedirs(temp_folder, exist_ok=True)
    def parse_timestamp_to_seconds(value):
        if value is None:
            return 0.0
        value = str(value).strip()
        try:
            parts = value.split(":")
            if len(parts) == 2:
                return float(int(parts[0]) * 60 + float(parts[1]))
            if len(parts) == 3:
                return float(int(parts[0]) * 3600 + int(parts[1]) * 60 + float(parts[2]))
        except (ValueError, TypeError):
            pass
        return 0.0
    def get_entries(data):
        """
        Read scenes from the SAME panel.json location used by the Google Flow
        image-generation function:
            panel_data["project_name"]["script_json"]["script"]
        Supports both:
          1. New scene-by-scene script lists containing fields such as
             characters_reference and scene_Image_generation.
          2. Older nested batches containing an "entries" list and
             scene_pov_prompt.
        Caption text is taken from a narration/dialogue field if one exists.
        No separate JSON file is required.
        """
        project = data.get("project_name", {})
        script_json = project.get("script_json", {}) if isinstance(project, dict) else {}
        script_data = script_json.get("script", {}) if isinstance(script_json, dict) else {}
        if not isinstance(script_data, (dict, list)):
            print(
                "⚠️ [CAPTIONS] project_name.script_json.script exists but is not "
                f"a dictionary/list (found {type(script_data).__name__})."
            )
            return []
        collected = []
        seen_ids = set()
        # These fields identify a scene object without requiring the legacy
        # scene_pov_prompt field to exist.
        scene_markers = {
            "scene_pov_prompt",
            "scene_Image_generation",
            "characters_reference",
            "shot_number",
            "dialogue",
            "narration",
            "voiceover",
            "voice_over",
            "spoken_text",
        }
        def is_scene_entry(item):
            return isinstance(item, dict) and bool(scene_markers.intersection(item.keys()))
        def add_entry(item):
            if not isinstance(item, dict):
                return
            # Deduplicate the same dictionary if encountered through a wrapper.
            identity = id(item)
            if identity in seen_ids:
                return
            seen_ids.add(identity)
            collected.append(dict(item))
        def walk(node):
            if isinstance(node, list):
                for child in node:
                    if is_scene_entry(child):
                        add_entry(child)
                    elif isinstance(child, (dict, list)):
                        walk(child)
                return
            if not isinstance(node, dict):
                return
            # Legacy batch format: {"entries": [scene, scene, ...]}.
            entries_list = node.get("entries")
            if isinstance(entries_list, list):
                for child in entries_list:
                    if isinstance(child, dict):
                        add_entry(child)
            # New direct scene object, e.g. an item in script: [...].
            if is_scene_entry(node):
                add_entry(node)
                return
            # Recurse through nested script containers while avoiding a second
            # pass through an entries list already collected above.
            for key, value in node.items():
                if key == "entries":
                    continue
                if isinstance(value, (dict, list)):
                    walk(value)
        walk(script_data)
        def first_text(entry, keys):
            for key in keys:
                value = entry.get(key)
                if isinstance(value, str) and value.strip():
                    return value.strip()
                if isinstance(value, (int, float)) and not isinstance(value, bool):
                    return str(value)
            return ""
        caption_keys = (
            "dialogue",
            "narration",
            "voiceover",
            "voice_over",
            "voice_over_script",
            "spoken_text",
            "caption",
            "subtitle",
            "text",
        )
        result = []
        running_cursor = 0.0
        for index, entry in enumerate(collected):
            # A scene can qualify in the new format without scene_pov_prompt.
            # Ignore obvious non-scene metadata dictionaries.
            if not is_scene_entry(entry):
                continue
            raw_start = entry.get("start", entry.get("start_time", entry.get("scene_start")))
            raw_stop = entry.get("stop", entry.get("end", entry.get("stop_time", entry.get("scene_end"))))
            start = parse_timestamp_to_seconds(raw_start) if raw_start is not None else running_cursor
            stop = parse_timestamp_to_seconds(raw_stop) if raw_stop is not None else None
            raw_duration = entry.get("duration", entry.get("duration_seconds", entry.get("scene_duration")))
            try:
                explicit_duration = float(raw_duration) if raw_duration is not None else None
            except (ValueError, TypeError):
                explicit_duration = None
            if stop is not None and stop > start:
                duration = stop - start
            elif explicit_duration is not None and explicit_duration > 0:
                duration = explicit_duration
                stop = start + duration
            else:
                # A reasonable fallback when the scene has no timing metadata.
                duration = 5.0
                stop = start + duration
            if duration <= 0:
                duration = 5.0
                stop = start + duration
            item = dict(entry)
            item["start_seconds"] = start
            item["stop_seconds"] = stop
            item["duration_seconds"] = duration
            item["dialogue"] = first_text(entry, caption_keys)
            result.append(item)
            running_cursor = max(running_cursor, stop)
        # Preserve explicit timing order where present; for entries without
        # timestamps, the walk order is retained through running_cursor.
        result.sort(key=lambda item: item.get("start_seconds", 0.0))
        print(f"📚 [CAPTIONS] Read {len(result)} scene entries from panel.json")
        if result:
            caption_count = sum(bool(item.get("dialogue")) for item in result)
            print(f"📝 [CAPTIONS] {caption_count}/{len(result)} scenes contain caption/narration text")
            if caption_count == 0:
                print(
                    "⚠️ [CAPTIONS] Scene entries were found, but none contains dialogue/narration "
                    "text. Animated captions need a text field such as dialogue, narration, "
                    "voiceover, spoken_text, caption, or subtitle."
                )
        return result
    entries = get_entries(panel_data)
    if not entries:
        print("❌ No scene entries found in project_name.script_json.script (new and legacy formats were checked).")
        return None
    def get_sorted_images(folder_path):
        items = []
        for filename in os.listdir(folder_path):
            match = re.match(r"entry[_-]?(\d+)", filename, re.IGNORECASE)
            if not match or os.path.splitext(filename)[1].lower() not in {
                ".png", ".jpg", ".jpeg", ".gif", ".bmp", ".webp"
            }:
                continue
            items.append({
                "number": int(match.group(1)),
                "filename": filename,
                "path": os.path.join(folder_path, filename),
            })
        return sorted(items, key=lambda item: item["number"])
    image_files = get_sorted_images(source_folder)
    if not image_files:
        print("❌ No usable entry images found")
        return None
    def find_audio_file(title):
        downloads = os.path.join(os.path.expanduser("~"), "Downloads")
        if not os.path.isdir(downloads):
            return None
        audio_exts = (".mp3", ".wav", ".m4a", ".aac", ".ogg", ".flac", ".wma")
        matches = [
            os.path.join(downloads, name)
            for name in os.listdir(downloads)
            if name.lower().endswith(audio_exts) and title.lower() in name.lower()
        ]
        return max(matches, key=os.path.getmtime) if matches else None
    audio_file_path = find_audio_file(project_title)
    if audio_file_path:
        print(f"🎵 Found audio: {os.path.basename(audio_file_path)}")
    else:
        print("⚠️ No matching audio found; video will be silent")
    video_width, video_height = 1920, 1080
    for item in image_files:
        try:
            with Image.open(item["path"]) as im:
                video_width, video_height = im.size
            break
        except Exception:
            continue
    video_width = max(2, int(video_width) - int(video_width) % 2)
    video_height = max(2, int(video_height) - int(video_height) % 2)
    print(f"📐 Output size: {video_width}x{video_height}")
    def fit_image(image, width, height):
        image = image.convert("RGB")
        image.thumbnail((width, height), Image.Resampling.LANCZOS)
        canvas = Image.new("RGB", (width, height), (0, 0, 0))
        canvas.paste(image, ((width - image.width) // 2, (height - image.height) // 2))
        return canvas
    def load_font(size):
        candidates = [
            r"C:\Windows\Fonts\arialbd.ttf",
            r"C:\Windows\Fonts\arial.ttf",
            "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
            "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
        ]
        for font_path in candidates:
            try:
                return ImageFont.truetype(font_path, size)
            except Exception:
                continue
        return ImageFont.load_default()
    def wrap_words(draw, text, font, max_width):
        words = text.split()
        lines, current = [], ""
        for word in words:
            candidate = f"{current} {word}".strip()
            if draw.textbbox((0, 0), candidate, font=font, stroke_width=2)[2] <= max_width:
                current = candidate
            else:
                if current:
                    lines.append(current)
                # Keep exceptionally long words from running off-screen.
                chunk = ""
                for char in word:
                    test = chunk + char
                    if draw.textbbox((0, 0), test, font=font, stroke_width=2)[2] <= max_width:
                        chunk = test
                    else:
                        if chunk:
                            lines.append(chunk)
                        chunk = char
                current = chunk
        if current:
            lines.append(current)
        return lines
    def draw_animated_caption(frame_image, caption, local_time, duration):
        """
        Render text directly over the image: no caption panel/background.
        The typewriter starts at a fixed left edge and reveals characters to
        the right. Text uses a tiny black outline plus a soft black shadow.
        """
        caption = re.sub(r"\s+", " ", str(caption or "").strip())
        # A comma at the very end of a scene caption is a scene-boundary
        # punctuation artifact; remove only that trailing comma. Commas
        # inside the sentence remain unchanged.
        caption = re.sub(r",\s*$", "", caption).rstrip()
        if not caption:
            return frame_image
        width, height = frame_image.size
        font_size = max(24, min(64, int(width / 24)))
        font = load_font(font_size)
        draw = ImageDraw.Draw(frame_image)
        display_text = caption.upper()
        max_text_width = int(width * 0.84)
        full_lines = wrap_words(draw, display_text, font, max_text_width)
        # Finish typing by 72% of the scene, then hold the complete caption
        # on screen. This gives every final character time to display before
        # the next scene replaces it.
        safe_duration = max(0.001, float(duration))
        elapsed = max(0.0, float(local_time))
        typing_duration = max(0.001, safe_duration * 0.72)
        progress = min(1.0, elapsed / typing_duration)
        visible_count = min(
            len(display_text),
            max(0, int(progress * len(display_text))),
        )
        if progress >= 1.0:
            visible_count = len(display_text)
        if visible_count <= 0:
            return frame_image
        visible_lines = []
        remaining = visible_count
        for full_line in full_lines:
            if remaining <= 0:
                break
            take = min(len(full_line), remaining)
            visible_lines.append(full_line[:take])
            remaining -= take
            # Account for the spaces removed by word wrapping.
            if take == len(full_line) and remaining > 0:
                remaining = max(0, remaining - 1)
        line_gap = max(6, int(font_size * 0.20))
        metrics = []
        for line in visible_lines[-3:]:
            bbox = draw.textbbox((0, 0), line or " ", font=font, stroke_width=1)
            metrics.append((line, bbox[2] - bbox[0], bbox[3] - bbox[1]))
        if not metrics:
            return frame_image
        total_height = sum(row[2] for row in metrics) + line_gap * (len(metrics) - 1)
        bottom_margin = int(height * 0.075)
        y = max(4, height - bottom_margin - total_height)
        # Anchor all lines to the left edge of the FULL caption block. This
        # anchor does not move as characters appear, so the typewriter grows
        # only to the right rather than expanding from the center.
        full_block_width = max(
            (
                draw.textbbox((0, 0), line, font=font, stroke_width=1)[2]
                for line in full_lines[-3:]
            ),
            default=1,
        )
        x_left = max(10, (width - min(max_text_width, full_block_width)) // 2)
        for line, line_width, line_height in metrics:
            if line:
                # Small soft shadow, then a thin black edge and bright text.
                shadow_offset = max(2, font_size // 28)
                draw.text(
                    (x_left + shadow_offset, y + shadow_offset),
                    line,
                    font=font,
                    fill=(0, 0, 0),
                    stroke_width=2,
                    stroke_fill=(0, 0, 0),
                )
                draw.text(
                    (x_left, y),
                    line,
                    font=font,
                    fill=(255, 255, 255),
                    stroke_width=1,
                    stroke_fill=(0, 0, 0),
                )
            y += line_height + line_gap
        return frame_image
    def apply_image_motion(image, local_time, duration, scene_index):
        """Apply a subtle Ken Burns-style zoom/pan without changing caption layout."""
        width, height = image.size
        safe_duration = max(0.001, float(duration))
        progress = min(1.0, max(0.0, float(local_time) / safe_duration))
        # Alternate gentle zoom directions and pan directions from scene to scene.
        zoom = 1.0 + 0.045 * progress
        crop_width = max(2, int(width / zoom))
        crop_height = max(2, int(height / zoom))
        max_x = max(0, width - crop_width)
        max_y = max(0, height - crop_height)
        # Ease the motion so it starts and ends smoothly.
        eased = progress * progress * (3.0 - 2.0 * progress)
        direction = 1 if scene_index % 2 == 0 else -1
        x = int((max_x * eased) if direction > 0 else (max_x * (1.0 - eased)))
        y = int((max_y * eased) if (scene_index // 2) % 2 == 0 else (max_y * (1.0 - eased)))
        cropped = image.crop((x, y, x + crop_width, y + crop_height))
        return cropped.resize((width, height), Image.Resampling.LANCZOS)

    count = min(len(entries), len(image_files))
    if len(entries) != len(image_files):
        print(f"⚠️ Entry/image count differs: {len(entries)} entries, {len(image_files)} images; processing {count} pairs")
    prepared = []
    for index in range(count):
        entry = entries[index]
        image_info = image_files[index]
        duration = max(0.1, float(entry.get("duration_seconds", 5.0)))
        try:
            with Image.open(image_info["path"]) as source:
                base_image = fit_image(source, video_width, video_height)
            prepared.append({
                "number": image_info["number"],
                "image": base_image,
                "duration": duration,
                "caption": entry.get("dialogue", ""),
                "filename": image_info["filename"],
            })
            print(f"🎬 [{index + 1}/{count}] {image_info['filename']} — {duration:.1f}s")
        except Exception as exc:
            print(f"⚠️ Skipping unreadable image {image_info['path']}: {exc}")
    if not prepared:
        print("❌ No images could be processed")
        return None
    audio_duration = None
    audio_probe = None
    if audio_file_path:
        try:
            audio_probe = AudioFileClip(audio_file_path)
            audio_duration = float(audio_probe.duration)
            print(f"🎵 Audio duration: {audio_duration:.2f}s")
        except Exception as exc:
            print(f"⚠️ Could not read audio duration: {exc}")
        finally:
            if audio_probe is not None:
                try:
                    audio_probe.close()
                except Exception:
                    pass
    total_duration = sum(item["duration"] for item in prepared)
    if audio_duration and audio_duration > 0 and prepared:
        # Keep the final image visible through the exact end of the narration.
        # Earlier scenes keep their durations when they fit within the audio.
        previous_duration = sum(float(item["duration"]) for item in prepared[:-1])
        original_last_duration = max(0.1, float(prepared[-1]["duration"]))
        last_available = float(audio_duration) - previous_duration
        if last_available > 0:
            # Extend OR shorten only the final image so the video ends with audio.
            prepared[-1]["duration"] = last_available
            total_duration = float(audio_duration)
            change = last_available - original_last_duration
            if change > 0.01:
                print(f"🔄 Extended final image by {change:.2f}s to reach audio end")
            elif change < -0.01:
                print(f"✂️ Adjusted final image by {change:.2f}s so it ends with audio")
            else:
                print("🎯 Final image timing already matches the audio end")
        else:
            # If all preceding scenes alone exceed the audio, preserve the final
            # image and proportionally fit the preceding scenes into the remaining
            # time rather than cutting off the final image entirely.
            last_duration = min(original_last_duration, max(0.1, float(audio_duration) * 0.15))
            available_for_previous = max(0.0, float(audio_duration) - last_duration)
            if previous_duration > 0 and available_for_previous > 0:
                scale = available_for_previous / previous_duration
                for item in prepared[:-1]:
                    item["duration"] = max(0.01, float(item["duration"]) * scale)
            elif available_for_previous <= 0:
                prepared = prepared[-1:]
                last_duration = float(audio_duration)
            prepared[-1]["duration"] = last_duration
            total_duration = sum(float(item["duration"]) for item in prepared)
            # Remove tiny floating-point discrepancies so the last frame lands
            # on the audio endpoint.
            if prepared:
                prepared[-1]["duration"] = max(
                    0.01, float(audio_duration) - sum(float(item["duration"]) for item in prepared[:-1])
                )
                total_duration = float(audio_duration)
            print("⚠️ Scene timings exceeded the audio; fitted earlier scenes so the final image remains until audio ends")
    # Existing outputs are preserved. If the target project filename already
    # exists, it will be renamed to \<project>_old_N.mp4 before the new render
    # is written, where N is one greater than the highest existing old number.
    clips = []
    final_clip = None
    audio_clip = None
    output_filename = f"{normalized_project_title}.mp4"
    output_path = os.path.join(edited_folder, output_filename)
    try:
        print(f"🎞️ Building {len(prepared)} animated scene clips...")
        print("🎬 Transitions enabled: alternating crossfade, directional wipe, and slide")
        print("🖥️ Keep-awake request: preventing Windows sleep and display timeout during rendering")
        # Prevent Windows from sleeping or turning off the display during the
        # render. This is reverted in the finally block below.
        keep_awake_enabled = False
        try:
            if os.name == "nt":
                import ctypes
                ES_CONTINUOUS = 0x80000000
                ES_SYSTEM_REQUIRED = 0x00000001
                ES_DISPLAY_REQUIRED = 0x00000002
                result = ctypes.windll.kernel32.SetThreadExecutionState(
                    ES_CONTINUOUS | ES_SYSTEM_REQUIRED | ES_DISPLAY_REQUIRED
                )
                keep_awake_enabled = bool(result)
                print("✅ Windows keep-awake request active" if keep_awake_enabled
                      else "⚠️ Windows keep-awake request could not be confirmed")
            else:
                print("ℹ️ Keep-awake control is Windows-specific; continuing normally")
        except Exception as awake_exc:
            print(f"⚠️ Could not enable keep-awake: {awake_exc}")
        transition_duration = 0.45  # seconds per image change
        transition_types = ("crossfade", "wipe_left", "slide_left", "wipe_right", "crossfade", "slide_right")
        clips = []
        scene_start = 0.0
        # Show each next scene's image and caption 1 second before its narration
        # segment begins. The audio track and total video duration remain unchanged:
        # the first visual scene is shortened by 1 second and the final scene is
        # extended by 1 second; intermediate scenes keep their original duration.
        early_display_seconds = 1.0
        visual_durations = [float(item["duration"]) for item in prepared]
        if len(visual_durations) > 1:
            # Keep very short first scenes valid while preserving total duration.
            actual_early_shift = min(
                early_display_seconds,
                max(0.0, visual_durations[0] - 0.05),
            )
            visual_durations[0] -= actual_early_shift
            visual_durations[-1] += actual_early_shift
        for scene_index, item in enumerate(prepared):
            base_image = item["image"]
            duration = float(visual_durations[scene_index])
            caption_duration = float(item["duration"])
            caption = item["caption"]
            previous_image = prepared[scene_index - 1]["image"] if scene_index > 0 else None
            transition_type = transition_types[(scene_index - 1) % len(transition_types)] if scene_index > 0 else "none"
            active_transition = min(transition_duration, duration * 0.45) if previous_image is not None else 0.0
            def make_frame(
                t,
                base=base_image,
                text=caption,
                clip_duration=duration,
                previous=previous_image,
                effect=transition_type,
                trans_duration=active_transition,
                motion_scene_index=scene_index,
            ):
                t = max(0.0, float(t))
                animated_current = apply_image_motion(base, t, clip_duration, motion_scene_index)
                current = animated_current.copy()
                # Mix the previous scene into the beginning of this scene.
                # Transitions are deterministic so repeated renders are stable.
                if previous is not None and trans_duration > 0 and t < trans_duration:
                    progress = min(1.0, max(0.0, t / trans_duration))
                    old = previous
                    w, h = current.size
                    if effect == "crossfade":
                        current = Image.blend(old, current, progress)
                    elif effect in ("wipe_left", "wipe_right"):
                        current = old.copy()
                        reveal = int(w * progress)
                        if effect == "wipe_left":
                            if reveal > 0:
                                current.paste(animated_current.crop((0, 0, reveal, h)), (0, 0))
                        else:
                            if reveal > 0:
                                current.paste(animated_current.crop((w - reveal, 0, w, h)), (w - reveal, 0))
                    else:  # slide_left / slide_right
                        current = Image.new("RGB", (w, h), (0, 0, 0))
                        offset = int(w * progress)
                        if effect == "slide_left":
                            current.paste(old.crop((offset, 0, w, h)), (0, 0))
                            current.paste(animated_current.crop((0, 0, offset, h)), (w - offset, 0))
                        else:
                            current.paste(old.crop((0, 0, w - offset, h)), (offset, 0))
                            current.paste(animated_current.crop((w - offset, 0, w, h)), (0, 0))
                rendered = draw_animated_caption(current, text, t, clip_duration)
                return np.asarray(rendered, dtype=np.uint8)
            clip = VideoClip(frame_function=make_frame, duration=duration)
            clips.append(clip)
            print(
                f"🎞️ Clip {scene_index + 1}/{len(prepared)} ready: "
                f"{item.get('filename', 'entry')} | {duration:.2f}s | "
                f"transition={transition_type}"
            )
        final_clip = concatenate_videoclips(clips, method="chain")
        if audio_file_path and os.path.exists(audio_file_path):
            audio_clip = AudioFileClip(audio_file_path)
            if audio_duration and audio_duration > total_duration:
                try:
                    audio_clip = audio_clip.subclipped(0, total_duration)
                except AttributeError:
                    audio_clip = audio_clip.subclip(0, total_duration)
            elif audio_duration and audio_duration < total_duration:
                try:
                    final_clip = final_clip.subclipped(0, audio_duration)
                except AttributeError:
                    final_clip = final_clip.subclip(0, audio_duration)
                total_duration = audio_duration
            if hasattr(final_clip, "with_audio"):
                final_clip = final_clip.with_audio(audio_clip)
            else:
                final_clip = final_clip.set_audio(audio_clip)
        # Preserve the previous project output instead of deleting it.
        # Continue numbering after the highest existing \<project>_old_N.mp4.
        if os.path.isfile(output_path):
            old_pattern = re.compile(
                rf"^{re.escape(normalized_project_title)}_old_(\d+)\.mp4$",
                re.IGNORECASE,
            )
            existing_numbers = []
            for existing_name in os.listdir(edited_folder):
                match = old_pattern.match(existing_name)
                if match:
                    existing_numbers.append(int(match.group(1)))
            next_old_number = max(existing_numbers, default=0) + 1
            backup_filename = f"{normalized_project_title}_old_{next_old_number}.mp4"
            backup_path = os.path.join(edited_folder, backup_filename)
            while os.path.exists(backup_path):
                next_old_number += 1
                backup_filename = f"{normalized_project_title}_old_{next_old_number}.mp4"
                backup_path = os.path.join(edited_folder, backup_filename)
            os.replace(output_path, backup_path)
            print(f"📦 Preserved previous output as: {backup_path}")
        print(f"💾 Writing: {output_path}")
        print(f"   Duration: {total_duration:.2f}s | FPS: 24 | Size: {video_width}x{video_height}")
        print("📊 Rendering progress will be shown in this console. No extra window will open.")
        render_started = time.monotonic()
        try:
            final_clip.write_videofile(
                output_path,
                fps=24,
                codec="libx264",
                audio_codec="aac" if audio_file_path else None,
                preset="ultrafast",
                threads=max(2, min(8, os.cpu_count() or 2)),
                logger="bar",
            )
        finally:
            if keep_awake_enabled and os.name == "nt":
                try:
                    import ctypes
                    ctypes.windll.kernel32.SetThreadExecutionState(0x80000000)
                    print("🖥️ Windows sleep/display prevention released")
                except Exception as awake_restore_exc:
                    print(f"⚠️ Could not restore Windows power state: {awake_restore_exc}")
        elapsed_minutes = (time.monotonic() - render_started) / 60.0
        print(f"⏱️ Render elapsed time: {elapsed_minutes:.2f} minutes")
        if not os.path.exists(output_path) or os.path.getsize(output_path) == 0:
            raise RuntimeError("FFmpeg finished without creating a valid output file")
        print(f"✅ Video saved: {output_path}")
        print(f"📊 Size: {os.path.getsize(output_path) / (1024 * 1024):.2f} MB")
    except Exception as exc:
        print(f"❌ Video assembly failed: {exc}")
        traceback.print_exc()
        return None
    finally:
        # Safety net: release the Windows keep-awake request even if clip
        # construction or encoding failed before the normal release point.
        if locals().get("keep_awake_enabled", False) and os.name == "nt":
            try:
                import ctypes
                ctypes.windll.kernel32.SetThreadExecutionState(0x80000000)
                keep_awake_enabled = False
            except Exception:
                pass
        seen = set()
        for resource in [final_clip, audio_clip, *clips]:
            if resource is None or id(resource) in seen:
                continue
            seen.add(id(resource))
            try:
                resource.close()
            except Exception:
                pass
    # Clean temporary folder left by older versions, if present.
    try:
        if os.path.isdir(temp_folder):
            shutil.rmtree(temp_folder)
    except Exception as exc:
        print(f"⚠️ Could not clean temporary folder: {exc}")
    print("\n" + "=" * 60)
    print("📊 SUMMARY")
    print("=" * 60)
    print(f"   Project: {project_title}")
    print(f"   Entries found: {len(entries)}")
    print(f"   Images processed: {len(prepared)}")
    print(f"   Final duration: {total_duration:.2f}s")
    print("   Captions: animated typewriter reveal, pop-in, yellow current-word underline")
    print(f"   Audio: {'✅ Added' if audio_file_path else '❌ None'}")
    if audio_file_path:
        print(f"   Audio file: {os.path.basename(audio_file_path)}")
    print(f"   Output: {output_path}")
    print("=" * 60)
    print("✅ Complete!")
    completed_result = {
        "project_title": project_title,
        "entries_count": len(entries),
        "images_processed": len(prepared),
        "total_duration": total_duration,
        "audio_added": bool(audio_file_path),
        "audio_file": os.path.basename(audio_file_path) if audio_file_path else None,
        "output_folder": edited_folder,
        "output_file": output_filename,
        "output_path": output_path,
        "caption_animation": "typewriter_pop",
    }

    # Continue through READY_SCRIPT in the same invocation. The next call
    # re-scans the queue, skips this newly-created output, and selects the next
    # unfinished project. Return the current result if no further project is
    # eligible; otherwise return the last successful project's result.
    print("🔎 [READY-SCRIPT] Looking for the next unfinished project...")
    next_result = produce_video_from_images_entries()
    return next_result if isinstance(next_result, dict) else completed_result

def main():
    fetch_settings()
    distribute_characters()
    generate_gemini_images()
    generate_google_flow_images()
    produce_video_from_images_entries()
  
if __name__ == "__main__":
    print("🚀 Starting Google Flow image generation...", flush=True)

    try:
        generate_google_flow_images()
    except BaseException as e:
        import traceback
        print(f"❌ Fatal error: {e!r}", flush=True)
        traceback.print_exc()
        input("Press Enter to close...")


   

         
