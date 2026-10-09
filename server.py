"""Small standard-library Helpdesk API and static file server. Run: python server.py"""
import hashlib, hmac, json, mimetypes, os, secrets, sqlite3, threading
from email.parser import BytesParser
from email.policy import default
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse, unquote, parse_qs

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
UPLOADS = DATA / "uploads"
DB = DATA / "helpdesk.sqlite3"
MAX_BODY = 24 * 1024 * 1024
MAX_FILE = 8 * 1024 * 1024
SESSIONS = {}
LOCK = threading.RLock()

def connect():
    c = sqlite3.connect(DB, timeout=10); c.row_factory = sqlite3.Row; c.execute("PRAGMA foreign_keys=ON"); return c

def pw_hash(pw, salt=None):
    salt = salt or secrets.token_bytes(16)
    return salt.hex() + ":" + hashlib.pbkdf2_hmac("sha256", pw.encode(), salt, 240000).hex()

def pw_ok(pw, digest):
    try:
        salt, value = digest.split(":", 1)
        return hmac.compare_digest(hashlib.pbkdf2_hmac("sha256", pw.encode(), bytes.fromhex(salt), 240000).hex(), value)
    except Exception: return False

def initialize():
    DATA.mkdir(exist_ok=True); UPLOADS.mkdir(exist_ok=True)
    with connect() as c:
        c.executescript('''
        CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, name TEXT NOT NULL, password_hash TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ('staff','it_staff','admin')), category TEXT NOT NULL DEFAULT 'IT Staff', active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS tickets(id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL, requester_id INTEGER REFERENCES users(id), requester_name TEXT, requester_email TEXT, status TEXT NOT NULL DEFAULT 'Open' CHECK(status IN ('Open','In Progress','Closed')), priority TEXT NOT NULL DEFAULT 'Medium' CHECK(priority IN ('Low','Medium','High','Urgent')), created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, closed_at TEXT);
        CREATE TABLE IF NOT EXISTS user_ticket(user_id INTEGER REFERENCES users(id) ON DELETE CASCADE, ticket_id INTEGER REFERENCES tickets(id) ON DELETE CASCADE, assignment_role TEXT NOT NULL DEFAULT 'assignee', assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id,ticket_id));
        CREATE TABLE IF NOT EXISTS ticket_files(id INTEGER PRIMARY KEY, ticket_id INTEGER REFERENCES tickets(id) ON DELETE CASCADE, uploader_id INTEGER REFERENCES users(id), kind TEXT NOT NULL CHECK(kind IN ('screenshot','signed_form')), original_name TEXT NOT NULL, stored_name TEXT NOT NULL UNIQUE, mime_type TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS forms(id INTEGER PRIMARY KEY, title TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'Pending signature', comments TEXT DEFAULT '', created_by INTEGER REFERENCES users(id), created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS ticket_form(ticket_id INTEGER REFERENCES tickets(id) ON DELETE CASCADE, form_id INTEGER REFERENCES forms(id) ON DELETE CASCADE, PRIMARY KEY(ticket_id,form_id));
        CREATE TABLE IF NOT EXISTS activity(id INTEGER PRIMARY KEY, ticket_id INTEGER REFERENCES tickets(id) ON DELETE CASCADE, user_id INTEGER REFERENCES users(id), action TEXT NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
        ''')
        user_columns={r['name'] for r in c.execute('PRAGMA table_info(users)')}
        if 'category' not in user_columns: c.execute("ALTER TABLE users ADD COLUMN category TEXT NOT NULL DEFAULT 'IT Staff'")
        ticket_columns={r['name'] for r in c.execute('PRAGMA table_info(tickets)')}
        if 'requester_name' not in ticket_columns: c.execute('ALTER TABLE tickets ADD COLUMN requester_name TEXT')
        if 'requester_email' not in ticket_columns: c.execute('ALTER TABLE tickets ADD COLUMN requester_email TEXT')
        c.execute("UPDATE users SET category=CASE role WHEN 'admin' THEN 'Admin' ELSE 'IT Staff' END")
        c.execute("UPDATE users SET active=0 WHERE role='staff'")
        if c.execute("SELECT COUNT(*) FROM users").fetchone()[0] == 0:
            c.executemany("INSERT INTO users(username,name,password_hash,role,category) VALUES(?,?,?,?,?)", [(u,n,pw_hash('Helpdesk123!'),r,cat) for u,n,r,cat in [('admin','Workspace Admin','admin','Admin'),('itstaff','IT Support','it_staff','IT Staff')]])

def safe_user(row): return {k:row[k] for k in ('id','username','name','role','category','active')} if row else None
def get_user(c, uid): return c.execute("SELECT * FROM users WHERE id=? AND active=1", (uid,)).fetchone()
def ticket_dict(c, t):
    d = dict(t); d.pop('category_id',None); requester=c.execute('SELECT name FROM users WHERE id=?',(d['requester_id'],)).fetchone() if d['requester_id'] else None
    d['requester'] = d.get('requester_name') or (requester['name'] if requester else 'Public requester')
    d['assignees'] = [dict(x) for x in c.execute('SELECT u.id,u.name,u.username FROM user_ticket ut JOIN users u ON u.id=ut.user_id WHERE ut.ticket_id=? AND ut.assignment_role="assignee"',(d['id'],))]
    d['files'] = [dict(x) for x in c.execute('SELECT id,kind,original_name,mime_type,created_at FROM ticket_files WHERE ticket_id=? ORDER BY id',(d['id'],))]
    d['form'] = c.execute('SELECT f.id,f.title,f.status,f.comments FROM ticket_form tf JOIN forms f ON f.id=tf.form_id WHERE tf.ticket_id=? ORDER BY f.id DESC LIMIT 1',(d['id'],)).fetchone()
    d['form'] = dict(d['form']) if d['form'] else None
    return d

class Handler(BaseHTTPRequestHandler):
    server_version = 'Helpdesk/1.0'
    def log_message(self, fmt, *args): print('%s - %s' % (self.address_string(), fmt % args))
    def send_json(self, data, status=200):
        raw=json.dumps(data,ensure_ascii=False).encode(); self.send_response(status); self.send_header('Content-Type','application/json; charset=utf-8'); self.send_header('Content-Length',str(len(raw))); self.send_header('Cache-Control','no-store'); self.end_headers(); self.wfile.write(raw)
    def body(self):
        size=int(self.headers.get('Content-Length','0'))
        if size>MAX_BODY: raise ValueError('Request is too large.')
        raw=self.rfile.read(size)
        if 'multipart/form-data' in self.headers.get('Content-Type',''):
            msg=BytesParser(policy=default).parsebytes((b'Content-Type: '+self.headers['Content-Type'].encode()+b'\r\nMIME-Version: 1.0\r\n\r\n')+raw); fields={}; files=[]
            for part in msg.iter_parts():
                key=part.get_param('name',header='content-disposition'); filename=part.get_filename()
                if filename is None: fields[key]=part.get_payload(decode=True).decode('utf-8','replace')
                elif filename: files.append({'field':key,'name':filename,'mime':part.get_content_type(),'data':part.get_payload(decode=True)})
            fields['_files']=files; return fields
        return json.loads(raw or b'{}')
    def session(self):
        token=''
        for bit in self.headers.get('Cookie','').split(';'):
            if bit.strip().startswith('helpdesk_session='): token=bit.strip().split('=',1)[1]
        with LOCK: uid=SESSIONS.get(token)
        if not uid: return None
        with connect() as c: return get_user(c,uid)
    def require(self, roles=None):
        u=self.session()
        if not u: self.send_json({'error':'Please sign in.'},401); return None
        if roles and u['role'] not in roles: self.send_json({'error':'You do not have permission to do that.'},403); return None
        return u
    def do_GET(self):
        p=urlparse(self.path); path=unquote(p.path)
        if path.startswith('/api/'):
            u=self.require()
            if not u:return
            with connect() as c:
                if path=='/api/me': self.send_json({'user':safe_user(u)}); return
                if path=='/api/assignees':
                    rows=[dict(x) for x in c.execute("SELECT id,username,name,role,category,active FROM users WHERE active=1 AND role IN ('it_staff','admin') ORDER BY name")]; self.send_json({'users':rows}); return
                if path=='/api/users':
                    if u['role']!='admin': self.send_json({'error':'Admin access required.'},403); return
                    category=parse_qs(p.query).get('category',[None])[0]
                    sql='SELECT id,username,name,role,category,active FROM users WHERE role IN ("admin","it_staff")'
                    params=()
                    if category in ('Admin','IT Staff'): sql+=' AND category=?'; params=(category,)
                    sql+=' ORDER BY name'
                    rows=[]
                    for x in c.execute(sql,params): rows.append(dict(x))
                    self.send_json({'users':rows}); return
                if path=='/api/users/stats':
                    if u['role']!='admin': self.send_json({'error':'Admin access required.'},403); return
                    counts={r['category']:r['count'] for r in c.execute("SELECT category,COUNT(*) count FROM users WHERE active=1 AND role IN ('admin','it_staff') GROUP BY category")}
                    self.send_json({'admin':counts.get('Admin',0),'it_staff':counts.get('IT Staff',0),'total':sum(counts.values())}); return
                if path=='/api/reports':
                    scope=''; args=()
                    status=[dict(x) for x in c.execute('SELECT status label,COUNT(*) count FROM tickets'+scope+' GROUP BY status',args)]
                    priority=[dict(x) for x in c.execute('SELECT priority label,COUNT(*) count FROM tickets'+scope+' GROUP BY priority',args)]
                    requester=[dict(x) for x in c.execute('SELECT COALESCE(t.requester_name,u.name,"Public requester") label,COUNT(*) count FROM tickets t LEFT JOIN users u ON u.id=t.requester_id GROUP BY label ORDER BY count DESC LIMIT 8')]
                    self.send_json({'status':status,'priority':priority,'requester':requester}); return
                if path=='/api/tickets':
                    rows=c.execute('SELECT * FROM tickets ORDER BY updated_at DESC')
                    self.send_json({'tickets':[ticket_dict(c,x) for x in rows]}); return
                if path.startswith('/api/files/'):
                    fid=path.rsplit('/',1)[1]; f=c.execute('SELECT * FROM ticket_files WHERE id=?',(fid,)).fetchone()
                    if not f: self.send_json({'error':'File not found.'},404); return
                    t=c.execute('SELECT requester_id FROM tickets WHERE id=?',(f['ticket_id'],)).fetchone()
                    file=UPLOADS/f['stored_name']
                    if not file.is_file(): self.send_json({'error':'File not found.'},404); return
                    raw=file.read_bytes(); self.send_response(200); self.send_header('Content-Type',f['mime_type']); self.send_header('Content-Length',str(len(raw))); self.send_header('Content-Disposition',"inline; filename*=UTF-8''"+__import__('urllib.parse').parse.quote(f['original_name'])); self.end_headers(); self.wfile.write(raw); return
                self.send_json({'error':'Not found.'},404); return
        file=(ROOT / ('index.html' if path=='/' else path.lstrip('/'))).resolve()
        if ROOT not in file.parents and file!=ROOT/'index.html': self.send_error(403); return
        if not file.is_file(): self.send_error(404); return
        raw=file.read_bytes(); self.send_response(200); self.send_header('Content-Type',mimetypes.guess_type(str(file))[0] or 'application/octet-stream'); self.send_header('Content-Length',str(len(raw))); self.end_headers(); self.wfile.write(raw)
    def do_POST(self):
        path=urlparse(self.path).path
        try: data=self.body()
        except Exception as e: self.send_json({'error':str(e)},400); return
        if path=='/api/logout':
            token=''
            for bit in self.headers.get('Cookie','').split(';'):
                if bit.strip().startswith('helpdesk_session='): token=bit.strip().split('=',1)[1]
            with LOCK: SESSIONS.pop(token,None)
            raw=b'{"ok":true}'; self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Set-Cookie','helpdesk_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0'); self.send_header('Content-Length',str(len(raw))); self.end_headers(); self.wfile.write(raw); return
        if path=='/api/login':
            with connect() as c: u=c.execute('SELECT * FROM users WHERE username=? AND active=1',(str(data.get('username','')).strip(),)).fetchone()
            if not u or not pw_ok(str(data.get('password','')),u['password_hash']): self.send_json({'error':'Username or password is incorrect.'},401); return
            token=secrets.token_urlsafe(32)
            with LOCK: SESSIONS[token]=u['id']
            # Set the session cookie with the login response.
            raw=json.dumps({'user':safe_user(u)}).encode(); self.send_response(200); self.send_header('Content-Type','application/json'); self.send_header('Set-Cookie',f'helpdesk_session={token}; HttpOnly; SameSite=Strict; Path=/'); self.send_header('Content-Length',str(len(raw))); self.end_headers(); self.wfile.write(raw); return
        if path in ('/api/public/tickets','/api/tickets'):
            submitter=self.require() if path=='/api/tickets' else None
            if path=='/api/tickets' and not submitter:return
            title=str(data.get('title','')).strip(); desc=str(data.get('description','')).strip()
            name=str(data.get('name','')).strip()[:100] or (submitter['name'] if submitter else '')
            email=str(data.get('email','')).strip()[:254]
            if not title or len(title)>160 or not desc or len(desc)>10000:
                self.send_json({'error':'Enter a title (up to 160 characters) and description (up to 10,000 characters).'},400);return
            if email and ('@' not in email or any(ch.isspace() for ch in email)):
                self.send_json({'error':'Enter a valid email address or leave it blank.'},400);return
            with connect() as c:
                c.execute("INSERT INTO tickets(title,description,requester_id,requester_name,requester_email) VALUES(?,?,?,?,?)",(title,desc,submitter['id'] if submitter else None,name or 'Public requester',email or None)); tid=c.execute('SELECT last_insert_rowid()').fetchone()[0]
                c.execute("INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)",(tid,submitter['id'] if submitter else None,'Ticket submitted by signed-in user' if submitter else 'Ticket submitted through public form'))
                try:self.save_files(c,tid,submitter['id'] if submitter else None,data.get('_files',[]),'screenshot')
                except ValueError as e:c.rollback();self.send_json({'error':str(e)},400);return
                self.send_json({'ticket':ticket_dict(c,c.execute('SELECT * FROM tickets WHERE id=?',(tid,)).fetchone())},201)
            return
        u=self.require()
        if not u:return
        with connect() as c:
            if path.startswith('/api/tickets/') and path.endswith('/form'):
                if u['role'] not in ('it_staff','admin'):self.send_json({'error':'IT Staff or Admin access required.'},403);return
                try: tid=int(path.split('/')[3])
                except Exception:self.send_json({'error':'Invalid ticket.'},400);return
                t=c.execute('SELECT * FROM tickets WHERE id=?',(tid,)).fetchone()
                if not t:self.send_json({'error':'Ticket not found.'},404);return
                pending=c.execute("SELECT f.id FROM forms f JOIN ticket_form tf ON tf.form_id=f.id WHERE tf.ticket_id=? AND f.status='Pending signature' ORDER BY f.id DESC LIMIT 1",(tid,)).fetchone()
                if pending: fid=pending['id']
                else:
                    c.execute("INSERT INTO forms(title,status,created_by) VALUES(?, 'Pending signature', ?)",(f"Completion form for {t['title']}",u['id'])); fid=c.execute('SELECT last_insert_rowid()').fetchone()[0]
                    c.execute('INSERT INTO ticket_form(ticket_id,form_id) VALUES(?,?)',(tid,fid))
                    c.execute('INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)',(tid,u['id'],'Completion form prepared for signature'))
                self.send_json({'form_id':fid},201);return
            if path=='/api/users':
                if u['role']!='admin': self.send_json({'error':'Admin access required.'},403); return
                name=str(data.get('name','')).strip(); username=str(data.get('username','')).strip(); password=str(data.get('password','')); category=data.get('category')
                if not name or len(name)>100 or len(username)<3 or len(username)>60 or len(password)<10 or category not in ('Admin','IT Staff'): self.send_json({'error':'Enter a name, username, Admin or IT Staff category, and password of at least 10 characters.'},400); return
                try:
                    role='admin' if category=='Admin' else 'it_staff'
                    c.execute('INSERT INTO users(username,name,password_hash,role,category) VALUES(?,?,?,?,?)',(username,name,pw_hash(password),role,category)); uid=c.execute('SELECT last_insert_rowid()').fetchone()[0]
                except sqlite3.IntegrityError: self.send_json({'error':'That username is already in use.'},409); return
                self.send_json({'user':safe_user(get_user(c,uid))},201); return
        self.send_json({'error':'Not found.'},404)

    def save_files(self,c,tid,uid,files,kind):
        allowed={'screenshot':{'image/png','image/jpeg','image/gif'},'signed_form':{'application/pdf','image/png','image/jpeg'}}[kind]
        for f in files:
            if f['field'] not in ('screenshots','signed_form'): continue
            if f['mime'] not in allowed: raise ValueError('Unsupported file type: '+f['name'])
            if len(f['data'])>MAX_FILE: raise ValueError('Each file must be 8 MB or smaller.')
            clean=Path(f['name']).name[:180]; ext=Path(clean).suffix.lower()[:12]; stored=secrets.token_hex(18)+ext
            (UPLOADS/stored).write_bytes(f['data'])
            actual='signed_form' if f['field']=='signed_form' else kind
            c.execute('INSERT INTO ticket_files(ticket_id,uploader_id,kind,original_name,stored_name,mime_type) VALUES(?,?,?,?,?,?)',(tid,uid,actual,clean,stored,f['mime']))

    def do_PATCH(self):
        u=self.require(['it_staff','admin'])
        if not u:return
        path=urlparse(self.path).path
        try:data=self.body()
        except Exception as e:self.send_json({'error':str(e)},400);return
        try:tid=int(path.split('/')[3])
        except Exception:self.send_json({'error':'Invalid ticket.'},400);return
        with connect() as c:
            t=c.execute('SELECT * FROM tickets WHERE id=?',(tid,)).fetchone()
            if not t:self.send_json({'error':'Ticket not found.'},404);return
            if 'priority' in data:
                if data['priority'] not in ('Low','Medium','High','Urgent'):self.send_json({'error':'Invalid priority.'},400);return
                c.execute('UPDATE tickets SET priority=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',(data['priority'],tid))
            if 'assignees' in data:
                ids=data['assignees']
                if not isinstance(ids,list):self.send_json({'error':'Assignees must be a list.'},400);return
                if any(not c.execute("SELECT id FROM users WHERE id=? AND active=1 AND role IN ('it_staff','admin')",(int(x),)).fetchone() for x in ids):self.send_json({'error':'Select active IT staff accounts.'},400);return
                c.execute("DELETE FROM user_ticket WHERE ticket_id=? AND assignment_role='assignee'",(tid,))
                for uid in set(map(int,ids)):c.execute("INSERT INTO user_ticket(user_id,ticket_id,assignment_role) VALUES(?,?,'assignee')",(uid,tid))
                if ids and t['status']=='Open':c.execute("UPDATE tickets SET status='In Progress',updated_at=CURRENT_TIMESTAMP WHERE id=?",(tid,))
            if 'status' in data:
                status=data['status']
                if status not in ('Open','In Progress','Closed'):self.send_json({'error':'Invalid status.'},400);return
                if status=='Closed':
                    signed=c.execute("SELECT id FROM ticket_files WHERE ticket_id=? AND kind='signed_form'",(tid,)).fetchone()
                    if not signed:self.send_json({'error':'Upload the signed completion form before closing this ticket.'},400);return
                    c.execute("UPDATE tickets SET status='Closed',closed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?",(tid,))
                    c.execute("UPDATE forms SET status='Signed' WHERE id IN (SELECT form_id FROM ticket_form WHERE ticket_id=?)",(tid,))
                else:c.execute('UPDATE tickets SET status=?,closed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?',(status,tid))
            c.execute('INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)',(tid,u['id'],str(data.get('comment') or 'Ticket updated')[:500]))
            self.send_json({'ticket':ticket_dict(c,c.execute('SELECT * FROM tickets WHERE id=?',(tid,)).fetchone())})

    def do_PUT(self):
        path=urlparse(self.path).path
        try:data=self.body()
        except Exception as e:self.send_json({'error':str(e)},400);return
        if path.startswith('/api/users/'):
            u=self.require(['admin'])
            if not u:return
            try:uid=int(path.rsplit('/',1)[1])
            except Exception:self.send_json({'error':'Invalid user.'},400);return
            name=str(data.get('name','')).strip(); category=data.get('category')
            if not name or len(name)>100 or category not in ('IT Staff','Admin'):self.send_json({'error':'Enter a valid name and Admin/IT Staff category.'},400);return
            with connect() as c:
                role='admin' if category=='Admin' else 'it_staff'
                if not c.execute('UPDATE users SET name=?,role=?,category=? WHERE id=?',(name,role,category,uid)).rowcount:self.send_json({'error':'User not found.'},404);return
                self.send_json({'user':safe_user(get_user(c,uid))})
            return
        u=self.require(['it_staff','admin'])
        if not u:return
        try:tid=int(path.split('/')[3])
        except Exception:self.send_json({'error':'Invalid ticket.'},400);return
        with connect() as c:
            t=c.execute('SELECT * FROM tickets WHERE id=?',(tid,)).fetchone()
            if not t:self.send_json({'error':'Ticket not found.'},404);return
            files=data.get('_files',[])
            try:
                signed=[f for f in files if f['field']=='signed_form']
                if not signed:self.send_json({'error':'Choose a signed PDF or image form to upload.'},400);return
                self.save_files(c,tid,u['id'],signed,'signed_form')
            except ValueError as e:self.send_json({'error':str(e)},400);return
            pending=c.execute("SELECT f.id FROM forms f JOIN ticket_form tf ON tf.form_id=f.id WHERE tf.ticket_id=? AND f.status='Pending signature' ORDER BY f.id DESC LIMIT 1",(tid,)).fetchone()
            if pending:
                fid=pending['id'];c.execute("UPDATE forms SET status='Signed',comments=? WHERE id=?",(str(data.get('comments',''))[:2000],fid))
            else:
                c.execute("INSERT INTO forms(title,status,comments,created_by) VALUES(?, 'Signed', ?, ?)",(f"Completion form for {t['title']}",str(data.get('comments',''))[:2000],u['id'])); fid=c.execute('SELECT last_insert_rowid()').fetchone()[0]
                c.execute('INSERT INTO ticket_form(ticket_id,form_id) VALUES(?,?)',(tid,fid))
            if t['status']=='Closed':c.execute("UPDATE tickets SET status='In Progress',closed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?",(tid,))
            c.execute('INSERT INTO activity(ticket_id,user_id,action) VALUES(?,?,?)',(tid,u['id'],'Signed completion form uploaded'))
            self.send_json({'ticket':ticket_dict(c,c.execute('SELECT * FROM tickets WHERE id=?',(tid,)).fetchone())},201)

    def do_DELETE(self):
        u=self.require(['admin'])
        if not u:return
        path=urlparse(self.path).path
        try:uid=int(path.rsplit('/',1)[1])
        except Exception:self.send_json({'error':'Invalid user.'},400);return
        if '/tickets/' in path:
            with connect() as c:
                files=[r['stored_name'] for r in c.execute('SELECT stored_name FROM ticket_files WHERE ticket_id=?',(uid,))]
                if not c.execute('DELETE FROM tickets WHERE id=?',(uid,)).rowcount:self.send_json({'error':'Ticket not found.'},404);return
            for name in files:
                try:(UPLOADS/name).unlink(missing_ok=True)
                except OSError:pass
            self.send_json({'ok':True});return
        if uid==u['id']:self.send_json({'error':'You cannot remove your own account.'},400);return
        with connect() as c:
            if c.execute("UPDATE users SET active=0 WHERE id=?",(uid,)).rowcount==0:self.send_json({'error':'User not found.'},404);return
            self.send_json({'ok':True})


if __name__=='__main__':
    initialize(); host=os.environ.get('HELPDESK_HOST','127.0.0.1'); port=int(os.environ.get('HELPDESK_PORT','8000'))
    print(f'Helpdesk ready at http://{host}:{port} (demo password: Helpdesk123!)')
    ThreadingHTTPServer((host,port),Handler).serve_forever()
