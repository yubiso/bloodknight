import React, { useState, useEffect } from 'react';
import { initializeApp } from 'firebase/app';
import { 
  getAuth, 
  onAuthStateChanged, 
  signInAnonymously, 
  signInWithCustomToken 
} from 'firebase/auth';
import { 
  getFirestore, 
  doc, 
  setDoc, 
  onSnapshot, 
  collection 
} from 'firebase/firestore';
import { 
  User, 
  LayoutDashboard, 
  Save, 
  Loader2, 
  LogOut,
  Sparkles
} from 'lucide-react';

// --- Firebase Configuration & Initialization ---
const firebaseConfig = JSON.parse(__firebase_config);
const app = initializeApp(firebaseConfig);
const auth = getAuth(app);
const db = getFirestore(app);
const appId = typeof __app_id !== 'undefined' ? __app_id : 'default-app-id';

// --- Main App Component ---
export default function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [activeTab, setActiveTab] = useState('dashboard');
  
  // This state holds the user's profile data (name, email, etc.)
  // It is synced in real-time with Firestore.
  const [profileData, setProfileData] = useState({
    displayName: 'Guest User',
    bio: 'I love coding!',
    theme: 'blue'
  });

  // 1. Authentication Effect
  useEffect(() => {
    const initAuth = async () => {
      try {
        if (typeof __initial_auth_token !== 'undefined' && __initial_auth_token) {
          await signInWithCustomToken(auth, __initial_auth_token);
        } else {
          await signInAnonymously(auth);
        }
      } catch (error) {
        console.error("Auth error:", error);
      }
    };

    initAuth();
    const unsubscribe = onAuthStateChanged(auth, (u) => {
      setUser(u);
      if (!u) setLoading(false);
    });
    return () => unsubscribe();
  }, []);

  // 2. Data Sync Effect
  // This listens to the specific document for the logged-in user.
  // When 'Profile' updates the doc, this listener fires, updating 'profileData',
  // which then causes the 'Dashboard' to re-render with the new name.
  useEffect(() => {
    if (!user) return;

    // Strict Path Rule: artifacts/{appId}/users/{userId}/{collectionName}/{docId}
    // We are storing profile info in the 'settings' collection, document 'profile_main'
    const profileDocRef = doc(db, 'artifacts', appId, 'users', user.uid, 'settings', 'profile_main');

    const unsubscribe = onSnapshot(profileDocRef, (docSnap) => {
      if (docSnap.exists()) {
        setProfileData(docSnap.data());
      } else {
        // Create default if it doesn't exist
        const defaultData = {
          displayName: 'Guest User',
          bio: 'Ready to start my journey.',
          theme: 'blue'
        };
        setDoc(profileDocRef, defaultData);
        setProfileData(defaultData);
      }
      setLoading(false);
    }, (error) => {
      console.error("Data sync error:", error);
      setLoading(false);
    });

    return () => unsubscribe();
  }, [user]);

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen bg-slate-50 text-slate-400">
        <Loader2 className="animate-spin w-8 h-8" />
      </div>
    );
  }

  return (
    <div className="flex h-screen bg-slate-100 font-sans text-slate-900">
      {/* Sidebar Navigation */}
      <aside className="w-64 bg-white border-r border-slate-200 hidden md:flex flex-col">
        <div className="p-6 border-b border-slate-100">
          <div className="flex items-center gap-2 text-indigo-600 font-bold text-xl">
            <Sparkles className="w-6 h-6" />
            <span>AppOne</span>
          </div>
        </div>
        
        <nav className="flex-1 p-4 space-y-2">
          <NavButton 
            active={activeTab === 'dashboard'} 
            onClick={() => setActiveTab('dashboard')} 
            icon={<LayoutDashboard className="w-5 h-5" />}
            label="Dashboard"
          />
          <NavButton 
            active={activeTab === 'profile'} 
            onClick={() => setActiveTab('profile')} 
            icon={<User className="w-5 h-5" />}
            label="My Profile"
          />
        </nav>

        <div className="p-4 border-t border-slate-100">
          <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-slate-50">
            <div className="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-bold text-sm">
              {profileData.displayName.charAt(0).toUpperCase()}
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium text-slate-900 truncate">
                {profileData.displayName}
              </p>
              <p className="text-xs text-slate-500 truncate">
                {user?.uid.slice(0, 8)}...
              </p>
            </div>
          </div>
        </div>
      </aside>

      {/* Main Content Area */}
      <main className="flex-1 overflow-auto">
        {/* Mobile Header */}
        <div className="md:hidden bg-white p-4 border-b border-slate-200 flex justify-between items-center sticky top-0 z-10">
          <span className="font-bold text-indigo-600">AppOne</span>
          <div className="flex gap-2">
            <button 
              onClick={() => setActiveTab('dashboard')}
              className={`p-2 rounded-lg ${activeTab === 'dashboard' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600'}`}
            >
              <LayoutDashboard className="w-5 h-5" />
            </button>
            <button 
              onClick={() => setActiveTab('profile')}
              className={`p-2 rounded-lg ${activeTab === 'profile' ? 'bg-indigo-50 text-indigo-600' : 'text-slate-600'}`}
            >
              <User className="w-5 h-5" />
            </button>
          </div>
        </div>

        <div className="p-6 md:p-12 max-w-4xl mx-auto">
          {activeTab === 'dashboard' ? (
            <DashboardView profileData={profileData} />
          ) : (
            <ProfileView 
              user={user} 
              initialData={profileData} 
              onSave={() => setActiveTab('dashboard')} 
            />
          )}
        </div>
      </main>
    </div>
  );
}

// --- Sub-Components ---

function NavButton({ active, onClick, icon, label }) {
  return (
    <button
      onClick={onClick}
      className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 font-medium ${
        active 
          ? 'bg-indigo-50 text-indigo-600 shadow-sm' 
          : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
      }`}
    >
      {icon}
      <span>{label}</span>
    </button>
  );
}

function DashboardView({ profileData }) {
  return (
    <div className="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
      <div className="bg-gradient-to-r from-indigo-500 to-purple-600 rounded-3xl p-8 text-white shadow-xl shadow-indigo-200">
        <h1 className="text-3xl md:text-4xl font-bold mb-2">
          Welcome back, {profileData.displayName}!
        </h1>
        <p className="text-indigo-100 text-lg opacity-90">
          Here is what's happening with your projects today.
        </p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <StatsCard label="Total Projects" value="12" color="bg-blue-50 text-blue-600" />
        <StatsCard label="Pending Tasks" value="5" color="bg-orange-50 text-orange-600" />
        <StatsCard label="Completed" value="84%" color="bg-emerald-50 text-emerald-600" />
      </div>

      <div className="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
        <h3 className="font-bold text-lg mb-4 text-slate-800">Your Bio Preview</h3>
        <p className="text-slate-600 leading-relaxed">
          "{profileData.bio}"
        </p>
        <div className="mt-4 pt-4 border-t border-slate-100 text-sm text-slate-400">
          This data is pulled live from your profile settings.
        </div>
      </div>
    </div>
  );
}

function StatsCard({ label, value, color }) {
  return (
    <div className={`p-6 rounded-2xl ${color} flex flex-col items-center justify-center text-center`}>
      <span className="text-4xl font-bold mb-1">{value}</span>
      <span className="text-sm font-medium opacity-80 uppercase tracking-wider">{label}</span>
    </div>
  );
}

function ProfileView({ user, initialData, onSave }) {
  const [formData, setFormData] = useState(initialData);
  const [isSaving, setIsSaving] = useState(false);
  const [message, setMessage] = useState(null);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsSaving(true);
    setMessage(null);

    try {
      const docRef = doc(db, 'artifacts', appId, 'users', user.uid, 'settings', 'profile_main');
      await setDoc(docRef, formData, { merge: true });
      
      setMessage({ type: 'success', text: 'Profile updated successfully!' });
      
      // Optional: switch back to dashboard after a delay
      setTimeout(() => {
        setIsSaving(false);
      }, 1000);
      
    } catch (error) {
      console.error(error);
      setMessage({ type: 'error', text: 'Failed to save changes.' });
      setIsSaving(false);
    }
  };

  return (
    <div className="max-w-xl mx-auto animate-in fade-in zoom-in-95 duration-300">
      <div className="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div className="p-6 border-b border-slate-100 bg-slate-50/50">
          <h2 className="text-xl font-bold text-slate-900">Edit Profile</h2>
          <p className="text-slate-500 text-sm mt-1">
            Update your personal details here. Changes reflect immediately on the dashboard.
          </p>
        </div>
        
        <form onSubmit={handleSubmit} className="p-6 space-y-6">
          <div className="space-y-2">
            <label className="block text-sm font-medium text-slate-700">Display Name</label>
            <input
              type="text"
              required
              value={formData.displayName}
              onChange={(e) => setFormData({ ...formData, displayName: e.target.value })}
              className="w-full px-4 py-2 rounded-lg border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all"
              placeholder="e.g. John Doe"
            />
            <p className="text-xs text-slate-400">This name will appear on your welcome screen.</p>
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-slate-700">Bio</label>
            <textarea
              value={formData.bio}
              onChange={(e) => setFormData({ ...formData, bio: e.target.value })}
              className="w-full px-4 py-2 rounded-lg border border-slate-200 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 outline-none transition-all h-32 resize-none"
              placeholder="Tell us a little about yourself..."
            />
          </div>

          {message && (
            <div className={`p-4 rounded-lg text-sm ${message.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
              {message.text}
            </div>
          )}

          <div className="flex justify-end pt-4">
            <button
              type="submit"
              disabled={isSaving}
              className="flex items-center gap-2 bg-indigo-600 text-white px-6 py-2.5 rounded-xl hover:bg-indigo-700 active:scale-95 transition-all disabled:opacity-50 disabled:cursor-not-allowed font-medium shadow-md shadow-indigo-200"
            >
              {isSaving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
              {isSaving ? 'Saving...' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
