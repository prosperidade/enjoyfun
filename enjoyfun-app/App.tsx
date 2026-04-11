import React, { useEffect, useRef, useState } from 'react';
import { StatusBar } from 'expo-status-bar';
import { View, ActivityIndicator, StyleSheet } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { NavigationContainer, DarkTheme, NavigationContainerRef } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';

import { colors } from '@/theme';
import { getToken } from '@/lib/auth';
import { setUnauthorizedHandler } from '@/api/client';
import { LoginScreen } from '@/screens/LoginScreen';
import { ChatScreen } from '@/screens/ChatScreen';
import { EventProvider } from '@/context/EventContext';

export type RootStackParamList = {
  Login: undefined;
  Chat: undefined;
};

const Stack = createNativeStackNavigator<RootStackParamList>();

const navTheme = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    background: colors.bg,
    card: colors.bg,
    text: colors.textPrimary,
    primary: colors.accent,
    border: colors.border,
  },
};

export default function App() {
  const [bootstrapping, setBootstrapping] = useState(true);
  const [initialRoute, setInitialRoute] = useState<keyof RootStackParamList>('Login');
  const navRef = useRef<NavigationContainerRef<RootStackParamList>>(null);

  useEffect(() => {
    (async () => {
      const token = await getToken();
      setInitialRoute(token ? 'Chat' : 'Login');
      setBootstrapping(false);
    })();
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => {
      navRef.current?.reset({ index: 0, routes: [{ name: 'Login' }] });
    });
    return () => setUnauthorizedHandler(null);
  }, []);

  if (bootstrapping) {
    return (
      <View style={styles.bootstrap}>
        <ActivityIndicator color={colors.accent} />
      </View>
    );
  }

  return (
    <SafeAreaProvider>
      <StatusBar style="light" />
      <EventProvider>
        <NavigationContainer ref={navRef} theme={navTheme}>
          <Stack.Navigator
            initialRouteName={initialRoute}
            screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}
          >
            <Stack.Screen name="Login" component={LoginScreen} />
            <Stack.Screen name="Chat" component={ChatScreen} />
          </Stack.Navigator>
        </NavigationContainer>
      </EventProvider>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  bootstrap: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
