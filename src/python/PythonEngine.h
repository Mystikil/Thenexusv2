#pragma once

#include <cstdint>
#include <string>
#include <vector>

class PythonEngine {
public:
        static PythonEngine& instance();

        bool init(const std::string& home, const std::string& modulePath, const std::string& entryScript);
        void shutdown();
        bool reload();
        bool call(const char* funcName);
        bool call(const char* funcName, const std::vector<std::string>& args);

        void onServerStart();
        void onServerStop();
        void onPlayerLogin(uint32_t guid, const std::string& name);
        void onPlayerLogout(uint32_t guid, const std::string& name);
        void onCreatureDeath(const std::string& killer, const std::string& victim);

        bool isReady() const { return ready_; }

private:
        PythonEngine() = default;
        ~PythonEngine() = default;

        PythonEngine(const PythonEngine&) = delete;
        PythonEngine& operator=(const PythonEngine&) = delete;

        bool ensureInitialized() const;
        bool importBootstrap();
        bool importBootstrapLocked();
        void releasePythonHome();

        void* mainThreadState_ = nullptr;
        void* bootstrapModule_ = nullptr;
        void* pythonHome_ = nullptr;
        std::string modulePath_;
        std::string entryScript_;
        std::string entryModule_;
        bool ready_ = false;
};
